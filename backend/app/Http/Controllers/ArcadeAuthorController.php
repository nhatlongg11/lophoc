<?php

namespace App\Http\Controllers;

use App\ArcadeContent;
use App\ArcadeSessions;
use App\ArcadeTemplates;
use App\GameGenerator;
use App\LearningAccess;
use App\QuestionMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ArcadeAuthorController extends Controller
{
    public function __construct(private LearningAccess $access, private ArcadeContent $content, private ArcadeSessions $sessions, private QuestionMedia $media, private GameGenerator $generator) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:180'], 'subject_id' => ['nullable', 'integer', 'min:1']]);
        $query = $this->access->table('arcade_rounds')->leftJoin('subjects', 'arcade_rounds.subject_id', '=', 'subjects.id')->select('arcade_rounds.*', 'subjects.name as subject_name')
            ->when($data['subject_id'] ?? null, fn ($q, $id) => $q->where('arcade_rounds.subject_id', $id))
            ->when($this->access->user()->role === 'teacher', fn ($q) => $q->where('teacher_id', $this->access->user()->id));
        if (! $request->boolean('archived')) {
            $query->whereNull('archived_at');
        }
        $search = $data['q'] ?? '';
        $rows = $query->when($search !== '', fn ($q) => $q->where('title', 'like', '%'.$search.'%'))->orderByDesc('arcade_rounds.id')->paginate(12);

        return response()->json($rows);
    }

    public function show(int $id): JsonResponse
    {
        $round = $this->sessions->ownedRound($id);
        $version = $this->access->table('arcade_versions')->find($round->draft_version_id);

        return response()->json(['round' => $round, 'revision' => $version->revision, 'content' => json_decode($version->content, true), 'versions' => $this->access->table('arcade_versions')->where('round_id', $id)->select('id', 'revision', 'created_at')->orderByDesc('revision')->limit(50)->get(), 'assignments' => $this->access->table('arcade_assignments')->where('round_id', $id)->get(), 'history' => $this->access->table('arcade_publish_history')->where('round_id', $id)->orderByDesc('id')->limit(50)->get()]);
    }

    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $data = $request->validate(['content' => ['required', 'array'], 'expected_revision' => ['required', 'integer', 'min:0']]);
        $content = $this->media->content($this->content->draft($data['content']));
        abort_unless($this->access->table('subjects')->find($content['subject_id']), 422, 'Môn học không thuộc tổ chức này.');
        $roundId = DB::transaction(function () use ($id, $data, $content) {
            if ($id) {
                $this->sessions->ownedRound($id);
                $round = $this->access->table('arcade_rounds')->where('id', $id)->lockForUpdate()->first();
                abort_if($round->archived_at, 422, 'Vòng học đã lưu trữ. Tạo bản sao để tiếp tục chỉnh sửa.');
                $current = $this->access->table('arcade_versions')->find($round->draft_version_id);
                abort_unless($current->revision === $data['expected_revision'], 409, 'Nội dung đã thay đổi. Mở lại bản mới nhất trước khi lưu.');
                $revision = $current->revision + 1;
                $roundId = $id;
            } else {
                $revision = 1;
                $roundId = $this->access->table('arcade_rounds')->insertGetId(['teacher_id' => $this->access->user()->id, 'title' => $content['title'], 'subject_id' => $content['subject_id'], 'grade' => $content['grade'], 'topic' => $content['topic'], 'created_at' => now(), 'updated_at' => now()]);
            }
            $versionId = $this->access->table('arcade_versions')->insertGetId(['round_id' => $roundId, 'author_id' => $this->access->user()->id, 'revision' => $revision, 'subject_id' => $content['subject_id'], 'title' => $content['title'], 'grade' => $content['grade'], 'topic' => $content['topic'], 'schema_version' => 'arcade-v1', 'difficulty' => max([1, ...array_column($content['activities'], 'difficulty')]), 'content' => json_encode($content, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
            $this->access->table('arcade_rounds')->where('id', $roundId)->update(['draft_version_id' => $versionId, 'title' => $content['title'], 'subject_id' => $content['subject_id'], 'grade' => $content['grade'], 'topic' => $content['topic'], 'updated_at' => now()]);
            $this->media->link($content, 'arcade_version', $versionId);
            $this->history($roundId, $versionId, 'draft_saved');
            $this->access->audit('draft_saved', 'arcade_round', $roundId, null, ['revision' => $revision]);

            return $roundId;
        });

        return response()->json(['id' => $roundId], $id ? 200 : 201);
    }

    private function history(int $id, ?int $versionId, string $action): void
    {
        $this->access->table('arcade_publish_history')->insert(['round_id' => $id, 'version_id' => $versionId, 'actor_id' => $this->access->user()->id, 'action' => $action, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function preview(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id): JsonResponse {
            $this->sessions->ownedRound($id);
            $round = $this->access->table('arcade_rounds')->where('id', $id)->lockForUpdate()->first();
            $this->content->validate(json_decode($this->access->table('arcade_versions')->find($round->draft_version_id)->content, true));
            $session = $this->sessions->start($id, true);
            $this->history($id, $round->draft_version_id, 'previewed');

            return response()->json($session);
        });
    }

    public function publish(int $id): JsonResponse
    {
        return DB::transaction(function () use ($id): JsonResponse {
            $this->sessions->ownedRound($id);
            $round = $this->access->table('arcade_rounds')->where('id', $id)->lockForUpdate()->first();
            abort_if($round->archived_at, 422);
            $this->content->validate(json_decode($this->access->table('arcade_versions')->find($round->draft_version_id)->content, true));
            abort_unless($this->access->table('arcade_publish_history')->where('round_id', $id)->where('version_id', $round->draft_version_id)->where('action', 'previewed')->exists(), 422, 'Xem thử bản nháp hiện tại trước khi phát hành.');
            $this->access->table('arcade_rounds')->where('id', $id)->update(['published_version_id' => $round->draft_version_id, 'status' => 'published', 'updated_at' => now()]);
            $this->history($id, $round->draft_version_id, 'published');
            $this->access->audit('published', 'arcade_round', $id, null, ['version_id' => $round->draft_version_id]);

            return response()->json(['message' => 'Đã phát hành phiên bản mới. Các lượt đã giao vẫn giữ nguyên nội dung.']);
        });
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id): JsonResponse {
            $this->sessions->ownedRound($id);
            $round = $this->access->table('arcade_rounds')->where('id', $id)->lockForUpdate()->first();
            abort_unless($round->published_version_id && ! $round->archived_at, 422, 'Phát hành vòng học trước khi giao.');
            $data = $request->validate([...$this->access->recipientRules(), 'allowed_attempts' => ['required', 'integer', 'between:1,100'], 'starts_at' => ['nullable', 'date'], 'due_at' => ['nullable', 'date']]);
            abort_if(! empty($data['starts_at']) && ! empty($data['due_at']) && Carbon::parse($data['due_at'])->lessThanOrEqualTo($data['starts_at']), 422, 'Hạn hoàn thành phải sau thời gian mở.');
            $this->access->connectStudents($round->teacher_id, $data['student_ids']);
            $assignment = $this->access->table('arcade_assignments')->insertGetId(['round_id' => $id, 'version_id' => $round->published_version_id, 'assigned_by' => $this->access->user()->id, 'student_ids' => json_encode(array_map('intval', $data['student_ids'])), 'allowed_attempts' => $data['allowed_attempts'], 'starts_at' => empty($data['starts_at']) ? null : Carbon::parse($data['starts_at'])->setTimezone(config('app.timezone'))->toDateTimeString(), 'due_at' => empty($data['due_at']) ? null : Carbon::parse($data['due_at'])->setTimezone(config('app.timezone'))->toDateTimeString(), 'created_at' => now(), 'updated_at' => now()]);
            $this->access->table('arcade_rounds')->where('id', $id)->update(['status' => 'assigned', 'updated_at' => now()]);
            $this->history($id, $round->published_version_id, 'assigned');
            $this->access->notify($data['student_ids'], 'Vòng học mới', 'Có vòng học mới trong Learning Arcade: '.$round->title, 'arcade');
            $this->access->audit('assigned', 'arcade_round', $id, null, ['assignment_id' => $assignment]);

            return response()->json(['id' => $assignment], 201);
        });
    }

    public function revoke(int $id): JsonResponse
    {
        $assignment = $this->access->table('arcade_assignments')->find($id);
        abort_unless($assignment, 404);
        $this->sessions->ownedRound($assignment->round_id);
        $this->access->table('arcade_assignments')->where('id', $id)->update(['revoked_at' => now(), 'updated_at' => now()]);
        $this->access->audit('revoked', 'arcade_assignment', $id);

        return response()->json(['message' => 'Đã thu hồi lượt giao; lịch sử học tập vẫn được lưu.']);
    }

    public function duplicate(int $id): JsonResponse
    {
        $round = $this->sessions->ownedRound($id);
        $content = json_decode($this->access->table('arcade_versions')->find($round->draft_version_id)->content, true);
        $content['title'] = mb_substr($content['title'], 0, 160).' (bản sao)';

        return $this->save(new Request(['content' => $content, 'expected_revision' => 0]));
    }

    public function archive(int $id): JsonResponse
    {
        $round = $this->sessions->ownedRound($id);
        $this->access->table('arcade_rounds')->where('id', $id)->update(['archived_at' => now(), 'status' => 'archived', 'updated_at' => now()]);
        $this->history($id, $round->published_version_id, 'archived');
        $this->access->audit('archived', 'arcade_round', $id);

        return response()->json(['message' => 'Đã lưu trữ vòng học. Kết quả và các phiên bản vẫn được bảo toàn.']);
    }

    public function importBank(Request $request): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $data = $request->validate(['bank_id' => ['required', 'integer'], 'engine' => ['required_without:engines', 'in:matching,word_builder,quick_challenge,memory_flip,fill_gap,word_defense'], 'engines' => ['sometimes', 'array', 'min:1', 'max:6'], 'engines.*' => ['in:matching,word_builder,quick_challenge,memory_flip,fill_gap,word_defense', 'distinct'], 'count' => ['sometimes', 'integer', 'between:1,30'], 'seed' => ['sometimes', 'string', 'max:100'], 'selection' => ['sometimes', 'in:manual,random,balanced'], 'question_ids' => ['sometimes', 'array', 'max:100'], 'question_ids.*' => ['string', 'max:64', 'distinct'], 'topic' => ['nullable', 'string', 'max:100']]);
        $bank = $this->access->table('question_banks')->where('id', $data['bank_id'])->where('owner_id', $this->access->user()->id)->whereNull('archived_at')->first();
        abort_unless($bank, 404);
        $questions = $this->access->table('bank_questions')->where('question_bank_id', $bank->id)->orderBy('sort_order')->pluck('content')->map(fn ($q) => json_decode($q, true))->all();

        return response()->json(['subject_id' => $bank->subject_id, 'grade' => $bank->grade, 'topic' => $bank->topic, ...(isset($data['engines']) ? $this->generator->generate($questions, $data, (array) $bank) : $this->content->bankActivities($questions, $data['engine']))]);
    }

    public function templates(): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');

        return response()->json(ArcadeTemplates::all());
    }
}
