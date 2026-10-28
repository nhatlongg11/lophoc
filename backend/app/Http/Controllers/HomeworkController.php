<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use App\QuestionEngine;
use App\QuestionMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class HomeworkController extends Controller
{
    public function __construct(private LearningAccess $access, private QuestionEngine $engine, private QuestionMedia $media) {}

    private function questions(int $id): array
    {
        return $this->access->table('assignment_questions')->where('assignment_id', $id)->orderBy('sort_order')->get()->map(function ($q) {
            $row = (array) $q;
            $row['options'] = json_decode($q->options, true);
            $row['answer'] = json_decode($q->answer, true);
            $row['media'] = $q->media ? json_decode($q->media, true) : null;
            $row['choice_media'] = $q->choice_media ? json_decode($q->choice_media, true) : [];

            return $row;
        })->all();
    }

    private function visible(object $attempt, object $assignment): object
    {
        $attempt->score = $attempt->score === null ? null : (float) $attempt->score;
        $attempt->answers = json_decode($attempt->answers, true);
        $attempt->questions = json_decode($attempt->question_snapshot, true);
        $attempt->grading = $attempt->grading ? json_decode($attempt->grading, true) : null;
        unset($attempt->question_snapshot);
        if ($this->access->user()->role === 'student' && (! $assignment->release_answers || ! $attempt->submitted_at)) {
            $attempt->questions = array_map(function ($q) {
                unset($q['answer'], $q['explanation']);

                return $q;
            }, $attempt->questions);
            $attempt->grading = null;
        }

        return $attempt;
    }

    public function author(Request $request, int $id): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $this->access->assignment($id, true);
        $data = $request->validate([
            'allowed_attempts' => ['required', 'integer', 'between:0,100'],
            'grade_policy' => ['required', Rule::in(['first', 'latest', 'highest'])],
            'time_limit_seconds' => ['nullable', 'integer', 'between:60,14400'],
            'release_answers' => ['boolean'],
            'auto_grade_only' => ['boolean'],
            'questions' => ['required', 'array', 'min:1', 'max:100'],
            'questions.*.type' => ['required', Rule::in(QuestionEngine::TYPES)],
            'questions.*.prompt' => ['required', 'string', 'max:10000'],
            'questions.*.options' => ['present', 'array', 'max:100'],
            'questions.*.answer' => ['present'],
            'questions.*.points' => ['required', 'numeric', 'gt:0', 'max:100'],
            'questions.*.media_url' => ['nullable', 'url:https', 'max:2048'],
            'questions.*.topic' => ['nullable', 'string', 'max:100'],
            'questions.*.media' => ['nullable', 'array'],
            'questions.*.choice_media' => ['sometimes', 'array', 'max:100'],
            'questions.*.explanation' => ['nullable', 'string', 'max:2000'],
        ]);
        $data['questions'] = $this->media->questions($data['questions']);
        DB::transaction(function () use ($data, $id) {
            $this->access->table('assignments')->where('id', $id)->lockForUpdate()->first();
            abort_if($this->access->table('assignment_attempts')->where('assignment_id', $id)->exists(), 422, 'Đã có lượt làm bài. Hãy tạo bài tập mới để thay đổi nội dung.');
            $this->access->table('assignment_questions')->where('assignment_id', $id)->delete();
            foreach ($data['questions'] as $i => $q) {
                abort_if(($data['auto_grade_only'] ?? false) && in_array($q['type'], ['short', 'essay', 'file'], true), 422, 'Bài tự chấm không được chứa câu tự luận hoặc tệp. Vui lòng chọn bài tập kết hợp.');
                $this->engine->validateDefinition($q);
                if (in_array($q['type'], ['single', 'image', 'audio', 'true_false'], true)) {
                    abort_unless(in_array($q['answer'], $q['options'], true), 422, 'Đáp án phải nằm trong các lựa chọn.');
                }
                if (in_array($q['type'], ['multiple', 'matching', 'ordering'], true)) {
                    abort_unless(is_array($q['answer']), 422, 'Đáp án của dạng câu hỏi này phải là danh sách.');
                }
                $this->access->table('assignment_questions')->insert(['assignment_id' => $id, 'type' => $q['type'], 'prompt' => $q['prompt'], 'options' => json_encode($q['options'], JSON_UNESCAPED_UNICODE), 'answer' => json_encode($q['answer'], JSON_UNESCAPED_UNICODE), 'points' => $q['points'], 'topic' => $q['topic'] ?? null, 'media_url' => $q['media_url'] ?? null, 'media' => isset($q['media']) ? json_encode($q['media']) : null, 'choice_media' => json_encode($q['choice_media'] ?? []), 'explanation' => $q['explanation'] ?? null, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->media->link($data['questions'], 'assignment', $id);
            unset($data['questions'], $data['auto_grade_only']);
            $this->access->table('assignments')->where('id', $id)->update([...$data, 'updated_at' => now()]);
            $this->access->audit('questions_authored', 'assignment', $id);
        });

        return response()->json(['message' => 'Đã lưu câu hỏi và chính sách làm bài.']);
    }

    public function questionList(int $id): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $this->access->assignment($id, true);

        return response()->json($this->questions($id));
    }

    public function start(int $id): JsonResponse
    {
        $this->access->requireRole('student');
        $assignment = $this->access->assignment($id);
        abort_if(($assignment->starts_at && now()->lessThan($assignment->starts_at)) || now()->greaterThan($assignment->due_at) || in_array($assignment->lifecycle, ['closed', 'archived'], true), 422, 'Bài tập chưa mở hoặc đã đóng.');
        $attempt = DB::transaction(function () use ($id, $assignment) {
            $this->access->table('assignments')->where('id', $id)->lockForUpdate()->first();
            $query = $this->access->table('assignment_attempts')->where('assignment_id', $id)->where('student_id', $this->access->user()->id);
            $draft = (clone $query)->whereNull('submitted_at')->first();
            if ($draft) {
                return $draft;
            }
            $count = $query->count();
            abort_if($assignment->allowed_attempts > 0 && $count >= $assignment->allowed_attempts, 422, 'Đã hết số lượt làm bài được phép.');
            $questions = $this->questions($id);
            abort_unless($questions, 422, 'Bài tập này sử dụng phần nộp bài văn bản hoặc tệp.');
            $expires = now()->addSeconds($assignment->time_limit_seconds ?: 86400 * 365);
            if ($expires->greaterThan($assignment->due_at)) {
                $expires = Carbon::parse($assignment->due_at);
            }
            $attemptId = $this->access->table('assignment_attempts')->insertGetId(['assignment_id' => $id, 'student_id' => $this->access->user()->id, 'attempt_number' => $count + 1, 'answers' => '{}', 'question_snapshot' => json_encode($questions, JSON_UNESCAPED_UNICODE), 'started_at' => now(), 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);

            return $this->access->table('assignment_attempts')->where('id', $attemptId)->first();
        });

        return response()->json($this->visible($attempt, $assignment));
    }

    public function save(Request $request, int $id): JsonResponse
    {
        return $this->persist($request, $id, false);
    }

    public function upload(Request $request, int $id): JsonResponse
    {
        $this->access->requireRole('student');
        $attempt = $this->access->table('assignment_attempts')->where('id', $id)->where('student_id', $this->access->user()->id)->first();
        abort_unless($attempt, 404);
        $this->access->assignment($attempt->assignment_id);
        abort_if($attempt->submitted_at || now()->greaterThan($attempt->expires_at), 422, 'Lượt làm bài đã kết thúc.');
        $data = $request->validate(['file' => ['required', 'file', 'mimes:pdf,docx,pptx,jpg,jpeg,png,webp', 'max:10240']]);
        $file = $data['file'];
        $fileId = $this->access->storeFile($file, 'attempts', ['attempt_id' => $id]);

        return response()->json(['id' => $fileId, 'name' => $file->getClientOriginalName()], 201);
    }

    public function submit(Request $request, int $id): JsonResponse
    {
        return $this->persist($request, $id, true);
    }

    private function persist(Request $request, int $id, bool $submit): JsonResponse
    {
        $this->access->requireRole('student');
        $data = $request->validate(['answers' => ['present', 'array', 'max:100']]);
        $attempt = DB::transaction(function () use ($id, $data, $submit) {
            $attempt = $this->access->table('assignment_attempts')->where('id', $id)->where('student_id', $this->access->user()->id)->lockForUpdate()->first();
            abort_unless($attempt, 404);
            $assignment = $this->access->assignment($attempt->assignment_id);
            abort_if($attempt->submitted_at, 409, 'Lượt làm bài này đã được nộp.');
            $expired = now()->greaterThan($attempt->expires_at);
            abort_if($expired && ! $submit, 422, 'Đã hết giờ. Hãy nộp các câu trả lời đã lưu.');
            $questions = json_decode($attempt->question_snapshot, true);
            $answers = $expired ? json_decode($attempt->answers, true) : $data['answers'];
            $validIds = array_map('strval', array_column($questions, 'id'));
            foreach (array_keys($answers) as $key) {
                abort_unless(in_array((string) $key, $validIds, true), 422, 'Câu trả lời không thuộc lượt làm bài.');
            }
            foreach ($questions as $q) {
                if ($q['type'] === 'file' && ! empty($answers[$q['id']])) {
                    abort_unless($this->access->table('attachments')->where('attempt_id', $id)->where('id', $answers[$q['id']])->exists(), 422, 'Tệp không thuộc lượt làm bài này.');
                }
            }
            $payload = ['answers' => json_encode($answers, JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
            if ($submit) {
                $grade = $this->engine->grade($questions, $answers, (float) $assignment->max_score);
                $payload += ['submitted_at' => now(), 'status' => $grade['status'], 'score' => $grade['score'], 'grading' => json_encode($grade['grading'])];
            }
            $this->access->table('assignment_attempts')->where('id', $id)->update($payload);
            if ($submit) {
                $this->syncFinal($assignment, (int) $attempt->student_id);
                $this->access->notify([$assignment->teacher_id], 'Học sinh đã nộp bài', $assignment->title, 'submission');
                $this->access->audit('attempt_submitted', 'assignment_attempt', $id);
            }

            return $this->access->table('assignment_attempts')->where('id', $id)->first();
        });

        return response()->json($this->visible($attempt, $this->access->assignment($attempt->assignment_id)));
    }

    public function history(int $id): JsonResponse
    {
        $assignment = $this->access->assignment($id, $this->access->user()->role !== 'student');
        $attempts = $this->access->table('assignment_attempts')->where('assignment_id', $id)->when($this->access->user()->role === 'student', fn ($q) => $q->where('student_id', $this->access->user()->id))->orderByDesc('id')->limit(200)->get()->map(fn ($a) => $this->visible($a, $assignment));

        return response()->json($attempts);
    }

    public function grade(Request $request, int $id): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $attempt = $this->access->table('assignment_attempts')->where('id', $id)->first();
        abort_unless($attempt && $attempt->submitted_at, 404);
        $assignment = $this->access->assignment($attempt->assignment_id, true);
        $data = $request->validate(['score' => ['required', 'numeric', 'min:0', 'max:'.$assignment->max_score], 'feedback' => ['required', 'string', 'max:10000'], 'reason' => ['required_if:override,true', 'nullable', 'string', 'max:1000'], 'override' => ['boolean']]);
        abort_if($attempt->status === 'graded' && empty($data['reason']), 422, 'Điều chỉnh điểm cần ghi rõ lý do.');
        DB::transaction(function () use ($attempt, $assignment, $data, $id) {
            $this->access->table('assignment_attempts')->where('id', $id)->update(['score' => $data['score'], 'feedback' => $data['feedback'], 'status' => 'graded', 'graded_by' => $this->access->user()->id, 'updated_at' => now()]);
            $this->syncFinal($assignment, (int) $attempt->student_id);
            $this->access->audit('attempt_graded', 'assignment_attempt', $id, ['score' => $attempt->score], $data);
            $this->access->notify([$attempt->student_id], 'Bài tập đã được chấm', $assignment->title, 'grade');
        });

        return response()->json(['message' => 'Đã lưu điểm và phản hồi.']);
    }

    private function syncFinal(object $assignment, int $studentId): void
    {
        $query = $this->access->table('assignment_attempts')->where('assignment_id', $assignment->id)->where('student_id', $studentId)->whereNotNull('submitted_at');
        $final = match ($assignment->grade_policy) {
            'first' => $query->orderBy('attempt_number')->first(),
            'latest' => $query->orderByDesc('attempt_number')->first(),
            default => $query->orderByRaw("CASE WHEN status = 'graded' THEN 0 ELSE 1 END")->orderByDesc('score')->orderBy('attempt_number')->first(),
        };
        if ($final) {
            $this->access->table('submissions')->updateOrInsert(['assignment_id' => $assignment->id, 'student_id' => $studentId], ['answer' => $final->answers, 'score' => $final->status === 'graded' ? $final->score : null, 'status' => $final->status, 'feedback' => $final->feedback, 'submitted_at' => $final->submitted_at, 'graded_by' => $final->graded_by, 'graded_at' => $final->status === 'graded' ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
