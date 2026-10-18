<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('learning:remind', function () {
    $now = now();
    $assignments = DB::table('assignments')
        ->join('assignment_classes', 'assignments.id', '=', 'assignment_classes.assignment_id')
        ->join('class_memberships', 'assignment_classes.class_id', '=', 'class_memberships.class_id')
        ->where('assignments.published', true)
        ->whereBetween('assignments.due_at', [$now, $now->copy()->addDay()])
        ->select('assignments.id', 'assignments.title', 'assignments.due_at', 'class_memberships.student_id')
        ->distinct()
        ->get();

    foreach ($assignments as $assignment) {
        $submitted = DB::table('submissions')
            ->where('assignment_id', $assignment->id)
            ->where('student_id', $assignment->student_id)
            ->whereIn('status', ['submitted', 'late', 'graded'])
            ->exists();
        $kind = "assignment_due:{$assignment->id}";
        $notified = DB::table('notifications')->where('user_id', $assignment->student_id)->where('kind', $kind)->exists();
        if (! $submitted && ! $notified) {
            DB::table('notifications')->insert([
                'user_id' => $assignment->student_id,
                'title' => 'Bài tập sắp hết hạn',
                'body' => "{$assignment->title} cần nộp trước {$assignment->due_at}.",
                'kind' => $kind,
                'url' => '/app/',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    $lessons = DB::table('lessons')
        ->join('class_memberships', 'lessons.class_id', '=', 'class_memberships.class_id')
        ->where('lessons.status', '!=', 'cancelled')
        ->whereBetween('lessons.starts_at', [$now, $now->copy()->addHours(2)])
        ->select('lessons.id', 'lessons.title', 'lessons.starts_at', 'class_memberships.student_id')
        ->get();

    foreach ($lessons as $lesson) {
        $kind = "lesson_soon:{$lesson->id}";
        if (! DB::table('notifications')->where('user_id', $lesson->student_id)->where('kind', $kind)->exists()) {
            DB::table('notifications')->insert([
                'user_id' => $lesson->student_id,
                'title' => 'Sắp đến giờ học',
                'body' => "{$lesson->title} bắt đầu lúc {$lesson->starts_at}.",
                'kind' => $kind,
                'url' => '/app/',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    $this->info('Đã kiểm tra nhắc lịch học và hạn nộp bài.');
})->purpose('Send in-app lesson and assignment reminders');

Schedule::command('learning:remind')->everyFifteenMinutes();
