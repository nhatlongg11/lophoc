<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportingController extends Controller
{
    public function __construct(private LearningAccess $access) {}

    private function aggregate(Request $request): array
    {
        $this->access->requireRole('teacher', 'admin', 'student');
        $data = $request->validate(['class_id' => ['nullable', 'integer'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $ids = $this->access->classIds();
        if (! empty($data['class_id'])) {
            $this->access->classAccess((int) $data['class_id']);
            $ids = [(int) $data['class_id']];
        }
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->subDays(29)->startOfDay();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfDay();
        abort_if($from->diffInDays($to) > 366, 422, 'Khoảng thống kê tối đa 366 ngày.');
        $members = $this->access->table('class_memberships')->whereIn('class_id', $ids)->pluck('student_id')->unique()->all();
        if ($this->access->user()->role === 'student') {
            $members = array_intersect($members, [$this->access->user()->id]);
        }
        $assignments = $this->access->table('assignments')->where('published', true)->whereIn('id', $this->access->table('assignment_classes')->whereIn('class_id', $ids)->select('assignment_id'))->whereBetween('due_at', [$from, $to])->get();
        $submitted = $this->access->table('submissions')->whereIn('student_id', $members)->whereIn('assignment_id', $assignments->pluck('id'))->whereNotNull('submitted_at')->get();
        $expected = 0;
        foreach ($assignments as $assignment) {
            $assignedClasses = $this->access->table('assignment_classes')->where('assignment_id', $assignment->id)->pluck('class_id');
            $expected += $this->access->table('class_memberships')->whereIn('class_id', $assignedClasses)->whereIn('student_id', $members)->distinct()->count('student_id');
        }
        $scores = [];
        foreach ($submitted as $submission) {
            $assignment = $assignments->firstWhere('id', $submission->assignment_id);
            if ($submission->status === 'graded' && $submission->score !== null && $assignment->max_score > 0) {
                $scores[] = 10 * $submission->score / $assignment->max_score;
            }
        }
        $lessons = $this->access->table('lessons')->whereIn('class_id', $ids)->where('status', '!=', 'cancelled')->whereBetween('starts_at', [$from, $to])->pluck('id');
        $attendance = $this->access->table('attendance_records')->whereIn('lesson_id', $lessons)->whereIn('student_id', $members)->get();
        $gameIds = $this->access->table('games')->whereIn('class_id', $ids)->pluck('id');
        $games = $this->access->table('game_attempts')->whereIn('game_id', $gameIds)->whereIn('student_id', $members)->whereBetween('created_at', [$from, $to])->get();
        $totalQuestions = $games->sum('correct_count') + $games->sum('incorrect_count');
        $activity = $games->groupBy(fn ($g) => Carbon::parse($g->created_at)->toDateString())->map(fn ($items, $day) => ['day' => $day, 'attempts' => $items->count(), 'accuracy' => $items->sum('correct_count') + $items->sum('incorrect_count') > 0 ? round(100 * $items->sum('correct_count') / ($items->sum('correct_count') + $items->sum('incorrect_count'))) : null])->sortBy('day')->values();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'student_count' => count($members), 'assignment_count' => $assignments->count(), 'expected_submissions' => $expected, 'submitted_count' => $submitted->count(), 'completion_rate' => $expected ? round(100 * $submitted->count() / $expected, 1) : null, 'average_score' => $scores ? round(array_sum($scores) / count($scores), 2) : null, 'pending_grading' => $submitted->where('status', '!=', 'graded')->count(), 'attendance_recorded' => $attendance->count(), 'attendance_rate' => $attendance->count() ? round(100 * $attendance->whereIn('status', ['present', 'late'])->count() / $attendance->count(), 1) : null, 'game_attempts' => $games->count(), 'game_accuracy' => $totalQuestions ? round(100 * $games->sum('correct_count') / $totalQuestions, 1) : null, 'practice_seconds' => $games->sum('duration_seconds'), 'activity' => $activity];
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->aggregate($request));
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->aggregate($request);
        $this->access->audit('exported', 'report', $this->access->user()->id, null, ['from' => $data['from'], 'to' => $data['to']]);

        return response()->streamDownload(function () use ($data) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Chỉ số', 'Giá trị'], ',', '"', '');
            $labels = ['student_count' => 'Học sinh', 'assignment_count' => 'Bài tập đến hạn', 'expected_submissions' => 'Lượt nộp dự kiến', 'submitted_count' => 'Đã nộp', 'completion_rate' => 'Hoàn thành (%)', 'average_score' => 'Điểm TB (thang 10)', 'pending_grading' => 'Chờ chấm', 'attendance_recorded' => 'Ghi nhận điểm danh', 'attendance_rate' => 'Có mặt (%)', 'game_attempts' => 'Lượt luyện tập', 'game_accuracy' => 'Chính xác trò chơi (%)', 'practice_seconds' => 'Thời gian luyện tập (giây)'];
            foreach ($labels as $key => $label) {
                fputcsv($output, [$label, $data[$key] ?? 'Chưa có dữ liệu'], ',', '"', '');
            }
            fclose($output);
        }, 'eduquest-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
