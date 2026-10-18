<?php

namespace App\Http\Controllers;

use App\ArcadeSessions;
use App\LearningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ArcadeOverviewController extends Controller
{
    public function __construct(private LearningAccess $access, private ArcadeSessions $sessions) {}

    private function studentRoundQuery(): mixed
    {
        $id = $this->access->user()->id;

        return $this->access->table('arcade_assignments')->join('arcade_rounds', 'arcade_assignments.round_id', '=', 'arcade_rounds.id')->join('arcade_versions', 'arcade_assignments.version_id', '=', 'arcade_versions.id')->join('subjects', 'arcade_versions.subject_id', '=', 'subjects.id')->join('school_classes', 'arcade_assignments.class_id', '=', 'school_classes.id')->whereIn('arcade_assignments.class_id', $this->access->classIds())->whereNull('arcade_assignments.revoked_at')->whereNull('arcade_rounds.archived_at')->where(fn ($q) => $q->whereNull('arcade_assignments.student_ids')->orWhereJsonContains('arcade_assignments.student_ids', $id));
    }

    public function rounds(Request $request): JsonResponse
    {
        $this->access->requireRole('student');
        $data = $request->validate(['subject_id' => ['nullable', 'integer'], 'grade' => ['nullable', 'string', 'max:40'], 'topic' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1'], 'difficulty' => ['nullable', 'integer', 'between:1,3'], 'completion' => ['nullable', 'in:not_started,in_progress,completed']]);
        $query = $this->studentRoundQuery()->when($data['subject_id'] ?? null, fn ($q, $value) => $q->where('arcade_versions.subject_id', $value))->when($data['grade'] ?? null, fn ($q, $value) => $q->where('arcade_versions.grade', $value))->when($data['topic'] ?? null, fn ($q, $value) => $q->where('arcade_versions.topic', $value));
        $query->when($data['difficulty'] ?? null, fn ($q, $value) => $q->where('arcade_versions.difficulty', $value));
        $completed = $this->access->table('arcade_sessions')->whereColumn('assignment_id', 'arcade_assignments.id')->where('student_id', $this->access->user()->id)->where('is_preview', false)->where('status', 'completed')->selectRaw('1');
        $started = $this->access->table('arcade_sessions')->whereColumn('assignment_id', 'arcade_assignments.id')->where('student_id', $this->access->user()->id)->where('is_preview', false)->selectRaw('1');
        if (($data['completion'] ?? '') === 'completed') {
            $query->whereExists($completed);
        } elseif (($data['completion'] ?? '') === 'in_progress') {
            $query->whereNotExists($completed)->whereExists($started);
        } elseif (($data['completion'] ?? '') === 'not_started') {
            $query->whereNotExists($started);
        }
        $rows = $query->select('arcade_assignments.*', 'arcade_versions.title', 'arcade_versions.grade', 'arcade_versions.topic', 'arcade_versions.subject_id', 'arcade_versions.revision', 'arcade_versions.difficulty', 'arcade_versions.content', 'subjects.name as subject_name', 'school_classes.name as class_name', 'arcade_rounds.is_demo')->orderByDesc('arcade_assignments.id')->paginate(12);
        $assignmentIds = collect($rows->items())->pluck('id');
        $sessions = $this->access->table('arcade_sessions')->where('student_id', $this->access->user()->id)->whereIn('assignment_id', $assignmentIds)->where('is_preview', false)->orderByDesc('id')->get()->groupBy('assignment_id');
        foreach ($rows->items() as $row) {
            $content = json_decode($row->content, true);
            unset($row->content);
            $history = $sessions->get($row->id, collect());
            $active = $history->first(fn ($s) => $s->status === 'active' && (! $s->expires_at || now()->lessThan($s->expires_at)));
            $completed = $history->where('status', 'completed');
            $row->activity_count = count($content['activities']);
            $row->engines = array_values(array_unique(array_column($content['activities'], 'engine')));
            $row->objectives = $content['objectives'];
            $row->description = $content['description'] ?? '';
            $row->attempt_count = $history->count();
            $row->best_accuracy = $completed->map(fn ($s) => json_decode($s->progress, true)['round_result']['accuracy'] ?? 0)->max();
            $row->best_stars = $completed->map(fn ($s) => json_decode($s->progress, true)['round_result']['stars'] ?? 0)->max() ?? 0;
            $row->active_session_id = $active?->id;
            $row->last_result_session_id = $completed->first()?->id;
            $row->available = (! $row->starts_at || now()->greaterThanOrEqualTo($row->starts_at)) && (! $row->due_at || now()->lessThan($row->due_at)) && ($active || $history->count() < $row->allowed_attempts);
            $row->unavailable_reason = $row->starts_at && now()->lessThan($row->starts_at) ? 'Chưa mở' : ($row->due_at && now()->greaterThanOrEqualTo($row->due_at) ? 'Đã hết hạn' : 'Đã hết lượt');
        }

        return response()->json($rows);
    }

    private function summary(int $studentId): array
    {
        $legacyXp = (int) $this->access->table('xp_transactions')->where('student_id', $studentId)->sum('amount');
        $xp = $legacyXp + (int) $this->access->table('arcade_rewards')->where('student_id', $studentId)->sum('amount');
        $results = $this->access->table('arcade_results')->where('student_id', $studentId);
        $mastery = (clone $results)->join('subjects', 'arcade_results.subject_id', '=', 'subjects.id')->groupBy('subject_id', 'topic', 'subjects.name')->selectRaw('subject_id, topic, subjects.name as subject_name, COUNT(*) as activities_completed, ROUND(AVG(accuracy), 2) as accuracy, SUM(correct_count) as correct_count, SUM(total_count) as total_count')->get()->map(function ($row) {
            $row->recommendation = $row->accuracy < 50 ? 'Ôn lại nền tảng với hoạt động dễ hơn.' : ($row->accuracy < 80 ? 'Tiếp tục luyện ở mức hiện tại.' : 'Sẵn sàng thử nội dung khó hơn khi giáo viên giao.');
            $row->suggested_difficulty = $row->accuracy < 50 ? 1 : ($row->accuracy < 80 ? 2 : 3);

            return $row;
        });
        $days = array_values(array_unique([...(clone $results)->selectRaw('DATE(created_at) as day')->distinct()->pluck('day')->all(), ...$this->access->table('game_attempts')->where('student_id', $studentId)->selectRaw('DATE(created_at) as day')->distinct()->pluck('day')->all()]));
        $cursor = now()->startOfDay();
        if (! in_array($cursor->toDateString(), $days, true)) {
            $cursor->subDay();
        }
        $streak = 0;
        while (in_array($cursor->toDateString(), $days, true)) {
            $streak++;
            $cursor->subDay();
        }
        $history = $this->access->table('arcade_sessions')->join('arcade_versions', 'arcade_sessions.version_id', '=', 'arcade_versions.id')->where('student_id', $studentId)->where('is_preview', false)->where('arcade_sessions.status', 'completed')->select('arcade_sessions.id', 'arcade_sessions.round_id', 'arcade_versions.title', 'arcade_sessions.progress', 'arcade_sessions.completed_at')->orderByDesc('arcade_sessions.id')->limit(10)->get()->map(function ($s) {
            $s->result = json_decode($s->progress, true)['round_result'];
            unset($s->progress);

            return $s;
        });

        return ['xp' => $xp, 'level' => 1 + intdiv($xp, 200), 'streak' => $streak, 'timezone' => config('app.timezone'), 'today_activities' => (clone $results)->where('arcade_results.created_at', '>=', now()->startOfDay())->count(), 'completed_rounds' => $this->access->table('arcade_sessions')->where('student_id', $studentId)->where('is_preview', false)->where('status', 'completed')->count(), 'mastery' => $mastery, 'achievements' => $this->access->table('student_achievements')->where('student_id', $studentId)->get(), 'history' => $history];
    }

    public function hub(): JsonResponse
    {
        $this->access->requireRole('student');
        $counts = $this->studentRoundQuery()->groupBy('arcade_versions.subject_id')->selectRaw('arcade_versions.subject_id, COUNT(*) as round_count')->pluck('round_count', 'subject_id');
        $subjects = $this->access->table('subjects')->get()->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'round_count' => $counts[$s->id] ?? 0]);
        $allowed = collect($this->sessions->studentAssignments())->pluck('id');
        $continue = $this->access->table('arcade_sessions')->join('arcade_versions', 'arcade_sessions.version_id', '=', 'arcade_versions.id')->where('student_id', $this->access->user()->id)->whereIn('assignment_id', $allowed)->where('is_preview', false)->where('status', 'active')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->select('arcade_sessions.id', 'arcade_sessions.round_id', 'arcade_versions.title')->orderByDesc('arcade_sessions.id')->limit(5)->get();

        return response()->json([...$this->summary($this->access->user()->id), 'subjects' => $subjects, 'continue_sessions' => $continue]);
    }

    public function mistakes(Request $request): JsonResponse
    {
        $this->access->requireRole('student');
        $rows = $this->access->table('arcade_mistakes')->where('student_id', $this->access->user()->id)->when(! $request->boolean('resolved'), fn ($q) => $q->whereNull('corrected_at'))->orderByDesc('last_wrong_at')->paginate(12);
        foreach ($rows->items() as $row) {
            $row->review = json_decode($row->review, true);
            $row->practice_available = collect($this->sessions->studentAssignments())->contains('round_id', $row->round_id) && $this->access->table('arcade_rounds')->where('id', $row->round_id)->whereNull('archived_at')->exists();
        }

        return response()->json($rows);
    }

    public function child(int $id): JsonResponse
    {
        $this->access->requireRole('parent');
        $identity = request()->attributes->get('identity_user', $this->access->user());
        abort_unless($this->access->table('parent_student_links')->where('parent_id', $identity->id)->where('student_id', $id)->exists(), 404);

        return response()->json($this->summary($id));
    }

    public function leaderboard(Request $request): JsonResponse
    {
        $this->access->requireRole('student', 'teacher', 'admin');
        $data = $request->validate(['class_id' => ['required', 'integer'], 'period' => ['required', 'in:week,month,all'], 'topic' => ['nullable', 'string', 'max:100']]);
        $this->access->classAccess($data['class_id']);
        $organization = DB::table('organizations')->find($this->access->user()->organization_id);
        if (! $organization->leaderboard_enabled) {
            return response()->json(['enabled' => false, 'rows' => []]);
        }
        $members = $this->access->table('class_memberships')->where('class_id', $data['class_id'])->pluck('student_id');
        $query = $this->access->table('arcade_sessions')->join('arcade_assignments', 'arcade_sessions.assignment_id', '=', 'arcade_assignments.id')->join('arcade_versions', 'arcade_sessions.version_id', '=', 'arcade_versions.id')->where('arcade_assignments.class_id', $data['class_id'])->whereIn('arcade_sessions.student_id', $members)->where('arcade_sessions.status', 'completed')->where('arcade_sessions.is_preview', false)->when($data['period'] !== 'all', fn ($q) => $q->where('arcade_sessions.completed_at', '>=', $data['period'] === 'month' ? now()->startOfMonth() : now()->startOfWeek()))->when($data['topic'] ?? null, fn ($q, $topic) => $q->where('arcade_versions.topic', $topic))->groupBy('arcade_sessions.student_id', 'arcade_sessions.round_id')->selectRaw('arcade_sessions.student_id, arcade_sessions.round_id, MAX(final_accuracy) as mastery, MAX(final_stars) as best_stars');
        $rows = DB::query()->fromSub($query, 'personal_bests')->groupBy('student_id')->selectRaw('student_id, ROUND(AVG(mastery), 2) as accuracy, SUM(best_stars) as stars')->orderByDesc('accuracy')->orderByDesc('stars')->orderBy('student_id')->limit(50)->get()->map(fn ($r) => ['alias' => 'Nhà khám phá '.substr(hash_hmac('sha256', (string) $r->student_id, config('app.key')), 0, 6), 'accuracy' => $r->accuracy, 'stars' => $r->stars, 'is_me' => $r->student_id === $this->access->user()->id]);

        return response()->json(['enabled' => true, 'rows' => $rows]);
    }

    private function reportData(Request $request, int $page = 1): array
    {
        $this->access->requireRole('teacher', 'admin');
        $data = $request->validate(['class_id' => ['nullable', 'integer'], 'round_id' => ['nullable', 'integer'], 'subject_id' => ['nullable', 'integer'], 'topic' => ['nullable', 'string', 'max:100'], 'engine' => ['nullable', 'in:matching,word_builder,quick_challenge,memory_flip,fill_gap,word_defense'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'], 'page' => ['nullable', 'integer', 'min:1']]);
        if (! empty($data['class_id'])) {
            $this->access->classAccess($data['class_id']);
        }
        if (! empty($data['round_id'])) {
            $this->sessions->ownedRound($data['round_id']);
        }
        $from = $request->date('from')?->startOfDay() ?? now()->subDays(30)->startOfDay();
        $to = $request->date('to')?->endOfDay() ?? now()->endOfDay();
        abort_if($from->greaterThan($to), 422, 'Ngày bắt đầu phải trước ngày kết thúc.');
        $assignments = $this->access->table('arcade_assignments')->join('arcade_rounds', 'arcade_assignments.round_id', '=', 'arcade_rounds.id')->join('arcade_versions', 'arcade_assignments.version_id', '=', 'arcade_versions.id')->whereIn('arcade_assignments.class_id', $this->access->classIds())->when($this->access->user()->role === 'teacher', fn ($q) => $q->where('arcade_rounds.teacher_id', $this->access->user()->id))->when($data['class_id'] ?? null, fn ($q, $id) => $q->where('arcade_assignments.class_id', $id))->when($data['round_id'] ?? null, fn ($q, $id) => $q->where('arcade_assignments.round_id', $id))->when($data['subject_id'] ?? null, fn ($q, $id) => $q->where('arcade_versions.subject_id', $id))->whereNull('arcade_assignments.revoked_at')->select('arcade_assignments.*')->get();
        $members = $this->access->table('class_memberships')->whereIn('class_id', $assignments->pluck('class_id'))->get()->groupBy('class_id');
        $eligible = [];
        foreach ($assignments as $assignment) {
            $specific = $assignment->student_ids ? json_decode($assignment->student_ids, true) : null;
            foreach ($members->get($assignment->class_id, collect()) as $member) {
                if ($specific === null || in_array($member->student_id, $specific, true)) {
                    $eligible[$member->student_id][] = $assignment->id;
                }
            }
        }
        $rows = $this->access->table('users')->whereIn('id', array_keys($eligible))->select('id', 'name')->orderBy('name')->paginate(20, ['*'], 'page', $page);
        $query = $this->access->table('arcade_results')->join('arcade_sessions', 'arcade_results.session_id', '=', 'arcade_sessions.id')->whereIn('arcade_sessions.assignment_id', $assignments->pluck('id'))->whereIn('arcade_results.student_id', array_keys($eligible))->whereBetween('arcade_results.created_at', [$from, $to])->when($data['topic'] ?? null, fn ($q, $topic) => $q->where('arcade_results.topic', $topic))->when($data['engine'] ?? null, fn ($q, $engine) => $q->where('arcade_results.engine', $engine));
        $summary = (clone $query)->selectRaw('COUNT(*) as activity_completions, ROUND(AVG(accuracy), 2) as average_accuracy, ROUND(AVG(duration_seconds), 1) as average_duration_seconds, SUM(hint_count) as hints_used')->first();
        $sessions = $this->access->table('arcade_sessions')->whereIn('assignment_id', $assignments->pluck('id'))->where('is_preview', false)->where(fn ($q) => $q->whereBetween('started_at', [$from, $to])->orWhereBetween('completed_at', [$from, $to]))->select('student_id', 'assignment_id', 'status')->get()->groupBy('student_id');
        $completedStudents = 0;
        foreach ($eligible as $studentId => $ids) {
            $done = $sessions->get($studentId, collect())->where('status', 'completed')->pluck('assignment_id')->unique()->all();
            if (! array_diff($ids, $done)) {
                $completedStudents++;
            }
        }
        $studentResults = (clone $query)->whereIn('arcade_results.student_id', collect($rows->items())->pluck('id'))->select('arcade_results.student_id', 'arcade_results.round_id', 'arcade_results.activity_key', 'arcade_results.accuracy', 'arcade_results.duration_seconds', 'arcade_results.id')->orderBy('arcade_results.id')->get()->groupBy('student_id');
        foreach ($rows->items() as $student) {
            $history = $studentResults->get($student->id, collect());
            $attempts = $sessions->get($student->id, collect());
            $student->assigned_rounds = count($eligible[$student->id]);
            $student->completed_rounds = $attempts->where('status', 'completed')->pluck('assignment_id')->unique()->count();
            $student->complete = $student->completed_rounds >= $student->assigned_rounds;
            $student->attempt_count = $attempts->count();
            $student->activities_completed = $history->count();
            $student->accuracy = $history->isEmpty() ? null : round($history->avg('accuracy'), 2);
            $student->average_duration_seconds = $history->isEmpty() ? null : round($history->avg('duration_seconds'), 1);
            $student->first_accuracy = $history->first()?->accuracy;
            $student->latest_accuracy = $history->last()?->accuracy;
            $deltas = $history->groupBy(fn ($r) => $r->round_id.':'.$r->activity_key)->filter(fn ($group) => $group->count() > 1)->map(fn ($group) => (float) $group->last()->accuracy - (float) $group->first()->accuracy);
            $student->improvement = $deltas->isEmpty() ? null : round($deltas->avg(), 2);
        }
        $byActivity = (clone $query)->groupBy('arcade_results.activity_key', 'arcade_results.engine')->selectRaw('arcade_results.activity_key, arcade_results.engine, COUNT(*) as completions, ROUND(AVG(accuracy), 2) as accuracy, ROUND(AVG(duration_seconds), 1) as duration_seconds')->get();
        $byTopic = (clone $query)->groupBy('arcade_results.topic')->selectRaw('arcade_results.topic, COUNT(*) as completions, ROUND(AVG(accuracy), 2) as accuracy, SUM(correct_count) as correct_count, SUM(total_count) as total_count')->get();
        $mistakes = $this->access->table('arcade_mistakes')->whereIn('round_id', $assignments->pluck('round_id'))->whereIn('student_id', array_keys($eligible))->whereBetween('last_wrong_at', [$from, $to])->when($data['topic'] ?? null, fn ($q, $topic) => $q->where('topic', $topic))->orderByDesc('wrong_count')->limit(10)->get()->map(fn ($m) => ['topic' => $m->topic, 'objective' => $m->objective, 'prompt' => json_decode($m->review, true)['prompt'], 'wrong_count' => $m->wrong_count, 'improved' => $m->corrected_at !== null]);

        return ['summary' => ['assigned_students' => count($eligible), 'completed_students' => $completedStudents, 'incomplete_students' => count($eligible) - $completedStudents, 'activity_completions' => $summary->activity_completions, 'average_accuracy' => $summary->average_accuracy, 'average_duration_seconds' => $summary->average_duration_seconds, 'attempt_count' => $sessions->flatten(1)->count(), 'hints_used' => (int) $summary->hints_used], 'students' => $rows, 'by_activity' => $byActivity, 'by_topic' => $byTopic, 'common_mistakes' => $mistakes, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'notes' => 'Học sinh hiện được giao bài; kết quả trong khoảng ngày được chọn. Hoàn thành nghĩa là hoàn tất tất cả lượt giao trong bộ lọc. Độ chính xác câu hỏi tính ở lần trả lời đầu; ghép/thẻ tính số cặp đúng trên tổng lượt ghép. Cải thiện so sánh cùng vòng và cùng hoạt động, không dùng tốc độ xếp hạng.'];
    }

    public function reports(Request $request): JsonResponse
    {
        return response()->json($this->reportData($request, max(1, $request->integer('page', 1))));
    }

    public function export(Request $request): StreamedResponse
    {
        $first = $this->reportData($request);
        $this->access->audit('exported', 'arcade_results', 0, null, ['from' => $first['from'], 'to' => $first['to']]);

        return response()->streamDownload(function () use ($request, $first) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Học sinh', 'Vòng được giao', 'Vòng hoàn thành', 'Lượt chơi', 'Độ chính xác (%)', 'Thời gian TB (giây)', 'Cải thiện (điểm %)'], ',', '"', '');
            for ($page = 1; $page <= $first['students']->lastPage(); $page++) {
                $data = $page === 1 ? $first : $this->reportData($request, $page);
                foreach ($data['students']->items() as $student) {
                    $name = preg_match('/^[=+@\-\t\r]/u', $student->name) ? "'".$student->name : $student->name;
                    fputcsv($out, [$name, $student->assigned_rounds, $student->completed_rounds, $student->attempt_count, $student->accuracy ?? '', $student->average_duration_seconds ?? '', $student->improvement ?? ''], ',', '"', '');
                }
            }
            fclose($out);
        }, 'eduquest-learning-arcade.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
