<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use App\QuestionEngine;
use App\QuestionMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class QuestionBankController extends Controller
{
    public function __construct(private LearningAccess $access, private QuestionEngine $engine, private QuestionMedia $media) {}

    public function index(): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $rows = $this->access->table('question_banks')->where('owner_id', $this->access->user()->id)->whereNull('archived_at')->orderByDesc('id')->limit(100)->get();
        foreach ($rows as $row) {
            $row->question_count = $this->access->table('bank_questions')->where('question_bank_id', $row->id)->count();
        }

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $bank = $this->access->table('question_banks')->where('owner_id', $this->access->user()->id)->where('id', $id)->whereNull('archived_at')->first();
        abort_unless($bank, 404);
        $bank->questions = $this->access->table('bank_questions')->where('question_bank_id', $id)->orderBy('sort_order')->pluck('content')->map(fn ($q, $i) => ['id' => 'legacy_'.$i, ...json_decode($q, true)])->all();

        $bank->versions = $this->access->table('question_bank_versions')->where('question_bank_id', $id)->select('revision', 'created_at')->orderByDesc('revision')->get();

        return response()->json($bank);
    }

    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        if ($id) {
            $this->show($id);
        }
        $data = $request->validate(['name' => ['required', 'string', 'max:180'], 'subject_id' => ['required', $this->access->exists('subjects')], 'grade' => ['nullable', 'string', 'max:40'], 'topic' => ['nullable', 'string', 'max:100'], 'difficulty' => ['required', Rule::in(['easy', 'medium', 'hard'])], 'learning_objective' => ['nullable', 'string', 'max:255'], 'questions' => ['required', 'array', 'min:1', 'max:100'], 'questions.*.type' => ['required', Rule::in(QuestionEngine::TYPES)], 'questions.*.prompt' => ['required', 'string', 'max:10000'], 'questions.*.options' => ['present', 'array', 'max:100'], 'questions.*.answer' => ['present'], 'questions.*.points' => ['required', 'numeric', 'gt:0', 'lte:100'], 'questions.*.media_url' => ['nullable', 'url:https', 'max:2048'], 'questions.*.topic' => ['nullable', 'string', 'max:100'], 'expected_revision' => ['sometimes', 'integer', 'min:0'], 'questions.*.id' => ['sometimes', 'string', 'max:64', 'distinct'], 'questions.*.difficulty' => ['sometimes', Rule::in(['easy', 'medium', 'hard'])], 'questions.*.learning_objective' => ['nullable', 'string', 'max:255'], 'questions.*.explanation' => ['nullable', 'string', 'max:2000'], 'questions.*.media' => ['nullable', 'array'], 'questions.*.choice_media' => ['sometimes', 'array', 'max:100']]);
        $data['questions'] = $this->media->questions($data['questions']);
        foreach ($data['questions'] as &$question) {
            $question = array_intersect_key($question, array_flip(['id', 'type', 'prompt', 'options', 'answer', 'points', 'media_url', 'topic', 'difficulty', 'learning_objective', 'explanation', 'media', 'choice_media']));
            $question['id'] ??= (string) Str::uuid();
        }
        unset($question);
        foreach ($data['questions'] as $q) {
            $this->engine->validateDefinition($q);
        }
        $bankId = DB::transaction(function () use ($data, $id) {
            $questions = $data['questions'];
            $expected = $data['expected_revision'] ?? null;
            unset($data['questions'], $data['expected_revision']);
            $revision = 1;
            if ($id) {
                $bankId = $id;
                $current = $this->access->table('question_banks')->where('id', $id)->lockForUpdate()->first();
                abort_if($expected !== null && $current->revision !== $expected, 409, 'Ngân hàng đã thay đổi. Tải lại trước khi lưu.');
                $revision = $current->revision + 1;
                if ($current->revision === 0) {
                    $old = $this->access->table('bank_questions')->where('question_bank_id', $id)->orderBy('sort_order')->pluck('content')->map(fn ($q) => json_decode($q, true))->all();
                    $this->access->table('question_bank_versions')->insert(['question_bank_id' => $id, 'revision' => 0, 'content' => json_encode($old), 'created_at' => now(), 'updated_at' => now()]);
                }
                $this->access->table('question_banks')->where('id', $id)->update([...$data, 'updated_at' => now()]);
                $this->access->table('bank_questions')->where('question_bank_id', $id)->delete();
            } else {
                $bankId = $this->access->table('question_banks')->insertGetId([...$data, 'owner_id' => $this->access->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($questions as $i => $q) {
                $this->access->table('bank_questions')->insert(['question_bank_id' => $bankId, 'content' => json_encode($q, JSON_UNESCAPED_UNICODE), 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->access->table('question_banks')->where('id', $bankId)->update(['revision' => $revision]);
            $this->access->table('question_bank_versions')->insert(['question_bank_id' => $bankId, 'revision' => $revision, 'content' => json_encode($questions, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            $this->media->link($questions, 'bank', $bankId);
            $this->access->audit($id ? 'updated' : 'created', 'question_bank', $bankId);

            return $bankId;
        });

        return response()->json(['id' => $bankId], $id ? 200 : 201);
    }

    public function archive(int $id): JsonResponse
    {
        $this->show($id);
        $this->access->table('question_banks')->where('id', $id)->update(['archived_at' => now()]);
        $this->access->audit('archived', 'question_bank', $id);

        return response()->json(['message' => 'Đã lưu trữ ngân hàng câu hỏi.']);
    }
}
