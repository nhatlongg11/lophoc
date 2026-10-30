<?php

namespace App\Http\Controllers;

use App\ArcadeSessions;
use App\ContentGrade;
use App\LearningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ArcadeCollectionController extends Controller
{
    public function __construct(private LearningAccess $access, private ArcadeSessions $sessions, private ArcadeAuthorController $author) {}

    private function owned(int $id): object
    {
        $this->access->requireRole('teacher', 'admin');
        $row = $this->access->table('arcade_collections')->where('id', $id)->where('owner_id', $this->access->user()->id)->whereNull('archived_at')->first();
        abort_unless($row, 404);

        return $row;
    }

    public function index(Request $request): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $data = $request->validate(['grade' => ['nullable', 'string', 'max:40'], 'subject_id' => ['nullable', 'integer', 'min:1']]);
        $rows = ContentGrade::filter($this->access->table('arcade_collections')->where('owner_id', $this->access->user()->id)->whereNull('archived_at'), 'grade', $data['grade'] ?? null)
            ->when($data['subject_id'] ?? null, fn ($q, $id) => $q->where('subject_id', $id))->orderByDesc('id')->get();
        foreach ($rows as $row) {
            $row->round_ids = $this->access->table('arcade_collection_rounds')->where('collection_id', $row->id)->orderBy('sort_order')->pluck('round_id');
            $assigned = $this->access->table('arcade_assignments')->where('collection_id', $row->id)->whereNull('revoked_at')->get();
            $expected = 0;
            $completed = 0;
            foreach ($assigned as $assignment) {
                $members = json_decode($assignment->student_ids, true) ?: [];
                $expected += count($members);
                $completed += $this->access->table('arcade_sessions')->where('assignment_id', $assignment->id)->whereIn('student_id', $members)->where('status', 'completed')->where('is_preview', false)->distinct()->count('student_id');
            }
            $row->assigned_count = $expected;
            $row->completed_count = $completed;
            $row->completion_rate = $expected ? round(100 * $completed / $expected, 1) : null;
        }

        return response()->json($rows);
    }

    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        if ($id) {
            $this->owned($id);
        }
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:4000'], 'grade' => ['nullable', Rule::in(ContentGrade::GRADES)], 'subject_id' => ['required', 'integer', $this->access->exists('subjects')], 'round_ids' => ['required', 'array', 'min:1', 'max:30'], 'round_ids.*' => ['integer', 'distinct']]);
        $grade = $data['grade'] ?? ($id ? $this->owned($id)->grade : null);
        foreach ($data['round_ids'] as $roundId) {
            $round = $this->sessions->ownedRound($roundId);
            abort_if($grade && (string) $round->grade !== (string) $grade, 422, 'Chọn các vòng học cùng lớp.');
            abort_unless($round->subject_id === $data['subject_id'] && ! $round->archived_at, 422, 'Chọn các vòng cùng môn và chưa lưu trữ.');
        }
        $id = DB::transaction(function () use ($data, $id) {
            $fields = array_intersect_key($data, array_flip(['title', 'description', 'subject_id', 'grade']));
            if ($id) {
                $this->access->table('arcade_collections')->where('id', $id)->update([...$fields, 'updated_at' => now()]);
                $this->access->table('arcade_collection_rounds')->where('collection_id', $id)->delete();
            } else {
                $id = $this->access->table('arcade_collections')->insertGetId([...$fields, 'owner_id' => $this->access->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($data['round_ids'] as $i => $round) {
                $this->access->table('arcade_collection_rounds')->insert(['collection_id' => $id, 'round_id' => $round, 'sort_order' => $i]);
            }
            $this->access->audit('collection_saved', 'arcade_collection', $id, null, $data);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $this->owned($id);
        $data = $request->validate([...$this->access->recipientRules(), 'allowed_attempts' => ['required', 'integer', 'between:1,100'], 'starts_at' => ['nullable', 'date'], 'due_at' => ['nullable', 'date']]);
        $assignments = DB::transaction(function () use ($id, $data) {
            $collection = $this->access->table('arcade_collections')->where('id', $id)->lockForUpdate()->first();
            $ids = [];
            foreach ($this->access->table('arcade_collection_rounds')->where('collection_id', $id)->orderBy('sort_order')->pluck('round_id') as $round) {
                if ($collection->grade) {
                    $row = $this->sessions->ownedRound($round);
                    $version = $this->access->table('arcade_versions')->where('id', $row->published_version_id)->first();
                    abort_unless($version && $version->grade === $collection->grade, 422, 'Hãy phát hành các vòng học đúng lớp của bộ sưu tập trước khi giao.');
                }
                $assignment = $this->author->assign(new Request($data), $round)->getData(true)['id'];
                $this->access->table('arcade_assignments')->where('id', $assignment)->update(['collection_id' => $id]);
                $ids[] = $assignment;
            }

            return $ids;
        });

        return response()->json(['assignment_ids' => $assignments], 201);
    }
}
