<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TimetableRecurrenceTest extends TestCase
{
    use RefreshDatabase;

    private function asUser(User $user): void
    {
        $token = 'schedule-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['user_id' => $user->id, 'organization_id' => $user->organization_id, 'token_hash' => hash('sha256', $token), 'mode' => $user->role === 'parent' ? 'parent' : 'account', 'parent_verified_until' => now()->addHour(), 'expires_at' => now()->addDay()]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    private function fixture(): array
    {
        $this->travelTo(now()->setDate(2026, 10, 12)->setTime(8, 0));
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $parent = User::factory()->create(['role' => 'parent']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Math']);
        $year = DB::table('school_years')->insertGetId(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2027-01-01']);
        $class = DB::table('school_classes')->insertGetId(['name' => 'A', 'teacher_id' => $teacher->id, 'subject_id' => $subject, 'school_year_id' => $year]);
        DB::table('class_memberships')->insert(['class_id' => $class, 'student_id' => $student->id]);
        DB::table('parent_student_links')->insert(['organization_id' => 1, 'parent_id' => $parent->id, 'student_id' => $student->id]);
        $rule = ['class_id' => $class, 'teacher_id' => $teacher->id, 'subject_id' => $subject, 'title' => 'Weekly Math', 'room' => 'Room 1', 'starts_on' => '2026-10-12', 'ends_on' => '2027-05-31', 'weekdays' => [1], 'start_time' => '09:00', 'end_time' => '09:45'];

        return [$teacher, $student, $parent, $rule];
    }

    public function test_weekly_occurrence_reschedule_preserves_other_weeks_and_attendance_identity(): void
    {
        [$teacher, $student, $parent, $rule] = $this->fixture();
        $this->asUser($teacher);
        $recurrence = $this->postJson('/api/timetable/recurrences', $rule)->assertCreated()->json('id');
        $this->assertDatabaseCount('lessons', 0);
        $this->asUser($student);
        $lesson = $this->getJson('/api/lessons?from=2026-10-12&to=2026-10-18')->assertOk()->assertJsonCount(1)->assertJsonPath('0.starts_at', '2026-10-12 09:00:00')->json('0');
        $this->getJson('/api/lessons?from=2026-10-12&to=2026-10-18')->assertJsonPath('0.id', $lesson['id']);
        $this->postJson('/api/timetable/recurrences', $rule)->assertForbidden();
        $this->asUser($teacher);
        $changed = [...$lesson, 'starts_at' => '2026-10-13T10:00:00+07:00', 'ends_at' => '2026-10-13T10:45:00+07:00', 'status' => 'rescheduled'];
        $this->putJson('/api/lessons/'.$lesson['id'], $changed)->assertOk()->assertJsonPath('is_exception', fn ($value) => in_array($value, [1, true], true));
        $this->postJson('/api/lessons/'.$lesson['id'].'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'late', 'note' => 'Bus']]])->assertOk();
        $this->asUser($student);
        $this->getJson('/api/lessons?from=2026-10-12&to=2026-10-18')->assertJsonCount(1)->assertJsonPath('0.starts_at', '2026-10-13 10:00:00');
        $this->getJson('/api/lessons?from=2026-10-19&to=2026-10-25')->assertJsonCount(1)->assertJsonPath('0.starts_at', '2026-10-19 09:00:00');
        $this->asUser($parent);
        $this->getJson('/api/lessons?from=2026-10-12&to=2026-10-18')->assertJsonCount(1);
        $this->getJson('/api/attendance/report?from=2026-10-12&to=2026-10-18')->assertOk()->assertJsonPath('students.0.rate', 100)->assertJsonPath('records.0.note', 'Bus');
        $this->postJson('/api/lessons/'.$lesson['id'].'/attendance', ['records' => []])->assertForbidden();
        $other = User::factory()->create(['role' => 'parent']);
        $this->asUser($other);
        $this->getJson('/api/attendance/report?student_id='.$student->id)->assertNotFound();
        $this->getJson('/api/lessons?from=2026-10-12&to=2026-10-18')->assertExactJson([]);
        $this->assertDatabaseHas('lessons', ['id' => $lesson['id'], 'recurrence_id' => $recurrence]);
    }

    public function test_recurrence_conflicts_include_unmaterialized_occurrences_and_holidays(): void
    {
        [$teacher, $student, $parent, $rule] = $this->fixture();
        $this->asUser($teacher);
        $id = $this->postJson('/api/timetable/recurrences', $rule)->assertCreated()->json('id');
        $this->postJson('/api/timetable/recurrences', [...$rule, 'start_time' => '09:30', 'end_time' => '10:00'])->assertUnprocessable();
        $this->postJson('/api/lessons', ['class_id' => $rule['class_id'], 'subject_id' => $rule['subject_id'], 'teacher_id' => $teacher->id, 'title' => 'Conflict', 'starts_at' => '2027-01-04 09:15', 'ends_at' => '2027-01-04 10:00', 'status' => 'scheduled'])->assertUnprocessable();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->asUser($admin);
        $this->postJson('/api/timetable/holidays', ['title' => 'School holiday', 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-19'])->assertCreated();
        $this->asUser($student);
        $this->getJson('/api/lessons?from=2026-10-19&to=2026-10-25')->assertExactJson([]);
        $this->asUser($teacher);
        $this->putJson('/api/timetable/recurrences/'.$id, [...$rule, 'starts_on' => '2026-10-26', 'start_time' => '10:00', 'end_time' => '10:45'])->assertOk();
        $this->asUser($student);
        $this->getJson('/api/lessons?from=2026-10-26&to=2026-11-01')->assertJsonPath('0.starts_at', '2026-10-26 10:00:00');
        $this->getJson('/api/lessons?from=2026-10-12&to=2026-10-18')->assertJsonPath('0.starts_at', '2026-10-12 09:00:00');
    }

    public function test_sparse_attendance_correction_keeps_omitted_records_and_records_history(): void
    {
        [$teacher, $student, $parent, $rule] = $this->fixture();
        $second = User::factory()->create(['role' => 'student']);
        DB::table('class_memberships')->insert(['class_id' => $rule['class_id'], 'student_id' => $second->id]);
        $this->asUser($teacher);
        $this->postJson('/api/timetable/recurrences', $rule)->assertCreated();
        $lesson = $this->getJson('/api/lessons?from=2026-10-12&to=2026-10-18')->json('0.id');
        $this->postJson('/api/lessons/'.$lesson.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'unexcused', 'note' => 'Absent']]])->assertOk();
        $this->postJson('/api/lessons/'.$lesson.'/attendance', ['records' => [['student_id' => $second->id, 'status' => 'late']]])->assertOk();
        $this->assertDatabaseHas('attendance_records', ['student_id' => $student->id, 'lesson_id' => $lesson, 'status' => 'unexcused', 'note' => 'Absent']);
        $this->getJson('/api/attendance/report?class_id='.$rule['class_id'].'&student_id='.$student->id.'&from=2026-10-12&to=2026-10-18')->assertOk()->assertJsonPath('records.0.status', 'unexcused')->assertJsonCount(1, 'records');
        $this->getJson('/api/lessons/'.$lesson.'/attendance/history')->assertOk()->assertJsonCount(3);
        $this->get('/api/attendance/export?from=2026-10-12&to=2026-10-18')->assertOk()->assertDownload('eduquest-attendance.csv');
    }

    public function test_removing_holiday_restores_generated_occurrence_but_not_individual_cancellation(): void
    {
        [$teacher, $student, $parent, $rule] = $this->fixture();
        $this->asUser($teacher);
        $this->postJson('/api/timetable/recurrences', $rule)->assertCreated();
        $lesson = $this->getJson('/api/lessons?from=2026-10-19&to=2026-10-25')->json('0');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->asUser($admin);
        $holiday = $this->postJson('/api/timetable/holidays', ['title' => 'Holiday', 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-19'])->assertCreated()->json('id');
        $this->getJson('/api/lessons?from=2026-10-19&to=2026-10-25')->assertJsonPath('0.status', 'cancelled');
        $this->deleteJson('/api/timetable/holidays/'.$holiday)->assertOk();
        $this->getJson('/api/lessons?from=2026-10-19&to=2026-10-25')->assertJsonPath('0.status', 'scheduled')->assertJsonPath('0.id', $lesson['id']);
        $this->putJson('/api/lessons/'.$lesson['id'], [...$lesson, 'status' => 'cancelled'])->assertOk();
        $this->getJson('/api/lessons?from=2026-10-19&to=2026-10-25')->assertJsonPath('0.status', 'cancelled')->assertJsonPath('0.is_exception', fn ($value) => in_array($value, [1, true], true));
    }
}
