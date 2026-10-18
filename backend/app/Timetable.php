<?php

namespace App;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Timetable
{
    public function __construct(private LearningAccess $access) {}

    public function lock(): void
    {
        DB::table('organizations')->where('id', $this->access->user()->organization_id)->lockForUpdate()->first();
    }

    public function projected(object $rule, Carbon $from, Carbon $to): array
    {
        $start = $from->copy()->max(Carbon::parse($rule->starts_on))->startOfDay();
        $end = $to->copy()->min(Carbon::parse($rule->ends_on))->endOfDay();
        $days = json_decode($rule->weekdays, true);
        $exceptions = isset($rule->id) ? $this->access->table('lessons')->where('recurrence_id', $rule->id)->where('is_exception', true)->pluck('occurrence_on')->all() : [];
        $holidays = $this->access->table('school_holidays')->where('starts_on', '<=', $end->toDateString())->where('ends_on', '>=', $start->toDateString())->get();
        $rows = [];
        for ($date = $start; $date->lessThanOrEqualTo($end); $date->addDay()) {
            $day = $date->toDateString();
            if (! in_array($date->isoWeekday(), $days, true) || in_array($day, $exceptions, true) || $holidays->contains(fn ($h) => $h->starts_on <= $day && $h->ends_on >= $day)) {
                continue;
            }
            $rows[] = ['class_id' => $rule->class_id, 'teacher_id' => $rule->teacher_id, 'subject_id' => $rule->subject_id, 'title' => $rule->title, 'room' => $rule->room, 'note' => $rule->note, 'starts_at' => $day.' '.$rule->start_time.':00', 'ends_at' => $day.' '.$rule->end_time.':00', 'status' => 'scheduled', 'occurrence_on' => $day];
        }

        return $rows;
    }

    public function materialize(Carbon $from, Carbon $to): void
    {
        abort_if($from->greaterThan($to) || $from->diffInDays($to) > 93, 422, 'Chọn khoảng lịch tối đa 93 ngày.');
        DB::transaction(function () use ($from, $to) {
            $this->lock();
            foreach ($this->access->table('timetable_recurrences')->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())->get() as $rule) {
                foreach ($this->projected($rule, $from, $to) as $row) {
                    $this->access->table('lessons')->insertOrIgnore([...$row, 'recurrence_id' => $rule->id, 'created_at' => now(), 'updated_at' => now()]);
                    $existing = $this->access->table('lessons')->where('recurrence_id', $rule->id)->where('occurrence_on', $row['occurrence_on'])->first();
                    if ($existing && ! $existing->is_exception && $existing->status === 'cancelled' && ! $this->access->table('attendance_records')->where('lesson_id', $existing->id)->exists()) {
                        $this->access->table('lessons')->where('id', $existing->id)->update([...$row, 'updated_at' => now()]);
                    }
                }
            }
        });
    }

    public function conflict(array $data, ?int $exceptId = null, ?int $exceptRule = null): void
    {
        if ($data['status'] === 'cancelled') {
            return;
        }
        $overlap = fn ($row): bool => $row['starts_at'] < $data['ends_at'] && $row['ends_at'] > $data['starts_at'] && ($row['class_id'] === $data['class_id'] || $row['teacher_id'] === $data['teacher_id'] || (! empty($data['room']) && $row['room'] === $data['room']));
        $existing = $this->access->table('lessons')->where('status', '!=', 'cancelled')->where('starts_at', '<', $data['ends_at'])->where('ends_at', '>', $data['starts_at'])->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->when($exceptRule, fn ($q) => $q->where(fn ($q) => $q->whereNull('recurrence_id')->orWhere('recurrence_id', '!=', $exceptRule)->orWhere('is_exception', true)))->get();
        foreach ($existing as $lesson) {
            if ($overlap((array) $lesson)) {
                throw ValidationException::withMessages(['starts_at' => 'Trùng lịch với lớp, giáo viên hoặc phòng học khác.']);
            }
        }
        $from = Carbon::parse($data['starts_at'])->startOfDay();
        $to = Carbon::parse($data['ends_at'])->endOfDay();
        $except = $exceptId ? $this->access->table('lessons')->find($exceptId) : null;
        foreach ($this->access->table('timetable_recurrences')->where('starts_on', '<=', $to->toDateString())->where('ends_on', '>=', $from->toDateString())->when($exceptRule, fn ($q) => $q->where('id', '!=', $exceptRule))->get() as $rule) {
            foreach ($this->projected($rule, $from, $to) as $row) {
                if ($except && $except->recurrence_id === $rule->id && $except->occurrence_on === $row['occurrence_on']) {
                    continue;
                }
                if ($overlap($row)) {
                    throw ValidationException::withMessages(['starts_at' => 'Trùng lịch định kỳ với lớp, giáo viên hoặc phòng học khác.']);
                }
            }
        }
    }
}
