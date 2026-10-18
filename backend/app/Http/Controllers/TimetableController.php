<?php

namespace App\Http\Controllers;

use App\LearningAccess;
use App\Timetable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TimetableController extends Controller
{
    public function __construct(private LearningAccess $access, private Timetable $timetable) {}

    public function index(): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');

        return response()->json($this->access->table('timetable_recurrences')->when($this->access->user()->role === 'teacher', fn ($q) => $q->where('teacher_id', $this->access->user()->id))->orderByDesc('id')->get()->map(function ($rule) {
            $rule->weekdays = json_decode($rule->weekdays, true);
            $rule->student_ids = json_decode($rule->student_ids, true);

            return $rule;
        }));
    }

    public function save(Request $request, ?int $id = null): JsonResponse
    {
        $this->access->requireRole('teacher', 'admin');
        $data = $request->validate([...$this->access->recipientRules(), 'teacher_id' => ['required', 'integer', $this->access->exists('users')->where('role', 'teacher')], 'subject_id' => ['required', 'integer', $this->access->exists('subjects')], 'title' => ['required', 'string', 'max:180'], 'room' => ['nullable', 'string', 'max:80'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'start_time' => ['required', 'date_format:H:i'], 'end_time' => ['required', 'date_format:H:i', 'after:start_time'], 'weekdays' => ['required', 'array', 'min:1', 'max:7'], 'weekdays.*' => ['integer', 'between:1,7', 'distinct'], 'note' => ['nullable', 'string', 'max:2000']]);
        abort_if($this->access->user()->role === 'teacher' && (int) $data['teacher_id'] !== $this->access->user()->id, 403);
        abort_if(Carbon::parse($data['starts_on'])->diffInDays($data['ends_on']) > 1096, 422, 'Một lịch định kỳ tối đa 3 năm.');
        $newId = DB::transaction(function () use ($data, $id) {
            $this->timetable->lock();
            if ($id) {
                $old = $this->access->table('timetable_recurrences')->find($id);
                abort_unless($old, 404);
                abort_unless($this->access->user()->role === 'admin' || $old->teacher_id === $this->access->user()->id, 404);
                abort_if($data['starts_on'] < now()->toDateString() || $data['starts_on'] < $old->starts_on, 422, 'Chọn ngày áp dụng từ hôm nay; lịch sử cũ được giữ nguyên.');
            }
            $candidate = (object) [...$data, 'room' => $data['room'] ?? null, 'note' => $data['note'] ?? null, 'weekdays' => json_encode($data['weekdays'])];
            foreach ($this->timetable->projected($candidate, Carbon::parse($data['starts_on']), Carbon::parse($data['ends_on'])) as $row) {
                $this->timetable->conflict($row, null, $id);
            }
            if ($id) {
                $this->access->table('timetable_recurrences')->where('id', $id)->update(['ends_on' => Carbon::parse($data['starts_on'])->subDay()->toDateString(), 'updated_at' => now()]);
                $future = $this->access->table('lessons')->where('recurrence_id', $id)->where('occurrence_on', '>=', $data['starts_on'])->where('is_exception', false)->get();
                foreach ($future as $lesson) {
                    $recorded = $this->access->table('attendance_records')->where('lesson_id', $lesson->id)->exists();
                    $this->access->table('lessons')->where('id', $lesson->id)->update($recorded ? ['is_exception' => true] : ['status' => 'cancelled', 'is_exception' => true]);
                }
            }
            $newId = $this->access->table('timetable_recurrences')->insertGetId([...$data, 'weekdays' => json_encode($data['weekdays']), 'student_ids' => json_encode(array_map('intval', $data['student_ids'])), 'created_at' => now(), 'updated_at' => now()]);
            $this->access->connectStudents($data['teacher_id'], $data['student_ids']);
            $this->access->audit($id ? 'recurrence_replaced' : 'recurrence_created', 'timetable_recurrence', $newId, $id ? $old : null, $data);

            return $newId;
        });

        return response()->json(['id' => $newId], $id ? 200 : 201);
    }

    public function holidays(): JsonResponse
    {
        $this->access->requireRole('admin', 'teacher', 'student', 'parent');

        return response()->json($this->access->table('school_holidays')->orderBy('starts_on')->get());
    }

    public function holiday(Request $request): JsonResponse
    {
        $this->access->requireRole('admin');
        $data = $request->validate(['title' => ['required', 'string', 'max:180'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on']]);
        $id = DB::transaction(function () use ($data) {
            $this->timetable->lock();
            $id = $this->access->table('school_holidays')->insertGetId([...$data, 'created_at' => now(), 'updated_at' => now()]);
            $lessons = $this->access->table('lessons')->where('is_exception', false)->whereNotNull('recurrence_id')->whereBetween('occurrence_on', [$data['starts_on'], $data['ends_on']])->get();
            foreach ($lessons as $lesson) {
                if (! $this->access->table('attendance_records')->where('lesson_id', $lesson->id)->exists()) {
                    $this->access->table('lessons')->where('id', $lesson->id)->update(['status' => 'cancelled', 'note' => $data['title']]);
                }
            }
            $this->access->audit('holiday_created', 'school_holiday', $id, null, $data);

            return $id;
        });

        return response()->json(['id' => $id], 201);
    }

    public function removeHoliday(int $id): JsonResponse
    {
        $this->access->requireRole('admin');
        DB::transaction(function () use ($id) {
            $this->timetable->lock();
            $holiday = $this->access->table('school_holidays')->find($id);
            abort_unless($holiday, 404);
            $this->access->table('school_holidays')->where('id', $id)->delete();
            $this->access->audit('holiday_removed', 'school_holiday', $id, $holiday);
        });

        return response()->json(['message' => 'Đã bỏ ngày nghỉ. Các tiết định kỳ sẽ hiện lại khi xem lịch.']);
    }
}
