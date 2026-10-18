<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use App\Timetable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttendanceReportController extends Controller
{
    public function __construct(private LearningAccess $access, private Timetable $timetable) {}

    private function report(Request $request): array
    {
        $this->access->requireRole('teacher', 'admin', 'student', 'parent');
        $data = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d'], 'class_id' => ['nullable', 'integer'], 'student_id' => ['nullable', 'integer'], 'lesson_id' => ['nullable', 'integer']]);
        $from = isset($data['from']) ? Carbon::parse($data['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($data['to']) ? Carbon::parse($data['to'])->endOfDay() : now()->endOfMonth();
        abort_if($from->greaterThan($to) || $from->diffInDays($to) > 366, 422, 'Chọn khoảng thống kê tối đa 366 ngày.');
        $classes = $this->access->scheduleClassIds();
        if (! empty($data['class_id'])) {
            $classId = (int) $data['class_id'];
            abort_unless(in_array($classId, $classes, true), 404);
            $classes = [$classId];
        }
        $role = $this->access->user()->role;
        $students = $this->access->table('class_memberships')->whereIn('class_id', $classes)->pluck('student_id')->unique()->all();
        if ($role === 'student') {
            $students = [$this->access->user()->id];
        } elseif ($role === 'parent') {
            $students = $this->access->table('parent_student_links')->where('parent_id', $this->access->user()->id)->pluck('student_id')->all();
        }
        if (! empty($data['student_id'])) {
            $studentId = (int) $data['student_id'];
            abort_unless(in_array($studentId, $students, true), 404);
            $students = [$studentId];
        }
        for ($cursor = $from->copy(); $cursor->lessThanOrEqualTo($to); $cursor->addDays(90)) {
            $this->timetable->materialize($cursor, $cursor->copy()->addDays(89)->min($to));
        }
        $lessons = $this->access->table('lessons')->whereIn('class_id', $classes)->where('status', '!=', 'cancelled')->whereBetween('starts_at', [$from, $to])->when($data['lesson_id'] ?? null, fn ($q, $id) => $q->where('id', $id))->get();
        $rows = $this->access->table('attendance_records')->join('users', 'attendance_records.student_id', '=', 'users.id')->join('lessons', 'attendance_records.lesson_id', '=', 'lessons.id')->whereIn('lesson_id', $lessons->pluck('id'))->whereIn('student_id', $students)->select('attendance_records.id', 'attendance_records.lesson_id', 'attendance_records.student_id', 'attendance_records.status', 'attendance_records.note', 'attendance_records.updated_at', 'users.name', 'lessons.title', 'lessons.starts_at', 'lessons.class_id')->orderBy('lessons.starts_at')->orderBy('users.name')->get();
        $summaries = $rows->groupBy('student_id')->map(function ($records) {
            $present = $records->whereIn('status', ['present', 'late'])->count();

            return ['student_id' => $records->first()->student_id, 'name' => $records->first()->name, 'recorded' => $records->count(), 'present' => $present, 'late' => $records->where('status', 'late')->count(), 'excused' => $records->where('status', 'excused')->count(), 'absent' => $records->whereIn('status', ['unexcused', 'absent'])->count(), 'rate' => round(100 * $present / $records->count(), 1)];
        })->values();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'lessons' => $lessons->count(), 'records' => $rows, 'students' => $summaries, 'by_lesson' => $rows->groupBy('lesson_id')->map(fn ($group) => ['lesson_id' => $group->first()->lesson_id, 'title' => $group->first()->title, 'recorded' => $group->count(), 'present' => $group->whereIn('status', ['present', 'late'])->count()])->values(), 'by_class' => $rows->groupBy('class_id')->map(fn ($group, $id) => ['class_id' => $id, 'recorded' => $group->count(), 'present' => $group->whereIn('status', ['present', 'late'])->count()])->values(), 'note' => 'Tỷ lệ dựa trên các buổi đã ghi nhận; buổi chưa điểm danh không được coi là có mặt.'];
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->report($request));
    }

    public function history(int $id): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $lesson = $this->access->table('lessons')->find($id);
        abort_unless($lesson, 404);
        $this->access->classAccess($lesson->class_id, true);

        return response()->json($this->access->table('audit_logs')->where('entity_type', 'attendance')->where('entity_id', $id)->orderByDesc('id')->limit(500)->get());
    }

    public function export(Request $request): StreamedResponse
    {
        $data = $this->report($request);
        $this->access->audit('exported', 'attendance', 0, null, ['from' => $data['from'], 'to' => $data['to']]);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Học sinh', 'Tiết học', 'Ngày', 'Trạng thái', 'Ghi chú'], ',', '"', '');
            foreach ($data['records'] as $record) {
                $safe = fn ($s) => preg_match('/^[=+@\-\t\r]/u', (string) $s) ? "'".$s : $s;
                fputcsv($out, array_map($safe, [$record->name, $record->title, $record->starts_at, $record->status, $record->note]), ',', '"', '');
            }
            fclose($out);
        }, 'eduquest-attendance.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
