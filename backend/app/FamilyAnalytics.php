<?php

namespace App;

use Illuminate\Support\Carbon;

class FamilyAnalytics
{
    public function __construct(private LearningAccess $access) {}

    public function child(int $id): array
    {
        $assignments = $this->access->table('assignments')->where('published', true)->whereNotIn('lifecycle', ['draft', 'archived'])->whereIn('id', $this->access->table('assignment_students')->where('student_id', $id)->select('assignment_id'))->get();
        $submissions = $this->access->table('submissions')->where('student_id', $id)->whereIn('assignment_id', $assignments->pluck('id'))->whereNotNull('submitted_at')->whereIn('status', ['submitted', 'graded'])->get();
        $legacy = $this->access->table('game_attempts')->join('games', 'game_attempts.game_id', '=', 'games.id')->where('game_attempts.student_id', $id)->select('game_attempts.*', 'games.title', 'games.subject_id')->get();
        $arcade = $this->access->table('arcade_results')->where('student_id', $id)->get();
        $subjectIds = $assignments->pluck('subject_id')->merge($legacy->pluck('subject_id'))->merge($arcade->pluck('subject_id'))->filter()->unique();
        $subjects = $this->access->table('subjects')->whereIn('id', $subjectIds)->select('id as subject_id', 'name')->get()->groupBy('subject_id');
        $bySubject = [];
        foreach ($subjects as $subjectId => $subjectRows) {
            $assignedIds = $assignments->where('subject_id', $subjectId)->pluck('id');
            $scores = $submissions->whereIn('assignment_id', $assignedIds)->where('status', 'graded')->whereNotNull('score')->sortBy('submitted_at')->map(fn ($s) => ['date' => $s->submitted_at, 'score' => round(100 * $s->score / max(0.01, $assignments->firstWhere('id', $s->assignment_id)->max_score), 2)])->values();
            $subjectLegacy = $legacy->where('subject_id', $subjectId);
            $subjectArcade = $arcade->where('subject_id', $subjectId);
            $total = $subjectLegacy->sum('correct_count') + $subjectLegacy->sum('incorrect_count') + $subjectArcade->sum('total_count');
            $correct = $subjectLegacy->sum('correct_count') + $subjectArcade->sum('correct_count');
            $bySubject[] = ['game_accuracy' => $total ? round(100 * $correct / $total, 1) : null, 'game_activities' => $subjectLegacy->count() + $subjectArcade->count(), 'subject_id' => $subjectId, 'name' => $subjectRows->first()->name, 'assigned' => $assignedIds->count(), 'completed' => $submissions->whereIn('assignment_id', $assignedIds)->count(), 'average_score_percent' => $scores->isEmpty() ? null : round($scores->avg('score'), 2), 'score_history' => $scores, 'recent_change' => $scores->count() < 2 ? null : round($scores->last()['score'] - $scores->first()['score'], 2)];
        }
        $trends = [];
        for ($week = now()->startOfWeek()->subWeeks(11); $week->lessThanOrEqualTo(now()); $week->addWeek()) {
            $end = $week->copy()->endOfWeek();
            $due = $assignments->filter(fn ($a) => Carbon::parse($a->due_at)->betweenIncluded($week, $end));
            $done = $submissions->whereIn('assignment_id', $due->pluck('id'))->count();
            $scores = $submissions->filter(fn ($s) => $s->status === 'graded' && $s->score !== null && Carbon::parse($s->submitted_at)->betweenIncluded($week, $end))->map(fn ($s) => 100 * $s->score / max(.01, $assignments->firstWhere('id', $s->assignment_id)->max_score));
            $trends[] = ['week' => $week->toDateString(), 'assigned' => $due->count(), 'completed' => $done, 'completion_rate' => $due->count() ? round(100 * $done / $due->count(), 1) : null, 'average_score_percent' => $scores->isEmpty() ? null : round($scores->avg(), 2)];
        }
        $attendance = $this->access->table('attendance_records')->where('student_id', $id)->get();
        $activities = $this->access->table('assignment_attempts')->join('assignments', 'assignment_attempts.assignment_id', '=', 'assignments.id')->where('student_id', $id)->whereNotNull('submitted_at')->select('assignment_attempts.id', 'assignments.title', 'assignment_attempts.submitted_at as at', 'assignment_attempts.score')->orderByDesc('assignment_attempts.submitted_at')->limit(30)->get()->map(fn ($row) => [...(array) $row, 'kind' => 'homework']);
        $games = $this->access->table('arcade_results')->join('arcade_versions', 'arcade_results.version_id', '=', 'arcade_versions.id')->where('arcade_results.student_id', $id)->select('arcade_results.id', 'arcade_versions.title', 'arcade_results.created_at as at', 'arcade_results.accuracy as score')->orderByDesc('arcade_results.id')->limit(30)->get()->map(fn ($row) => [...(array) $row, 'kind' => 'game']);

        $legacyHistory = $legacy->map(fn ($row) => ['id' => 'legacy_'.$row->id, 'title' => $row->title, 'at' => $row->created_at, 'score' => $row->correct_count + $row->incorrect_count ? round(100 * $row->correct_count / ($row->correct_count + $row->incorrect_count), 1) : null, 'kind' => 'game']);

        return ['assigned_count' => $assignments->count(), 'submitted_count' => $submissions->count(), 'graded_count' => $submissions->where('status', 'graded')->count(), 'attendance_recorded' => $attendance->count(), 'attendance_present' => $attendance->whereIn('status', ['present', 'late'])->count(), 'subjects' => $bySubject, 'weekly_trends' => $trends, 'homework' => $assignments->map(fn ($a) => ['id' => $a->id, 'title' => $a->title, 'due_at' => $a->due_at, 'completed' => $submissions->contains('assignment_id', $a->id)])->values(), 'activity_history' => $activities->concat($games)->concat($legacyHistory)->sortByDesc('at')->take(30)->values(), 'notifications' => $this->access->table('notifications')->where('user_id', $id)->select('id', 'title', 'body', 'created_at')->orderByDesc('id')->limit(20)->get()];
    }
}
