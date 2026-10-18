<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AttendanceDateLockTest extends TestCase
{
    use RefreshDatabase;

    private function lessonFor(string $startsAt): array
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $yearId = DB::table('school_years')->insertGetId(['name' => '2026–2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $subjectId = DB::table('subjects')->insertGetId(['name' => 'Toán học', 'color' => '#397c78', 'created_at' => now(), 'updated_at' => now()]);
        $classId = DB::table('school_classes')->insertGetId(['name' => '8A2', 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_year_id' => $yearId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('class_memberships')->insert(['class_id' => $classId, 'student_id' => $student->id, 'created_at' => now(), 'updated_at' => now()]);
        $lessonId = DB::table('lessons')->insertGetId(['class_id' => $classId, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'title' => 'Tiết Toán', 'starts_at' => $startsAt, 'ends_at' => CarbonImmutable::parse($startsAt)->addMinutes(45)->toDateTimeString(), 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);

        return [$teacher, $student, $lessonId];
    }

    private function headersFor(User $user): array
    {
        $token = 'attendance-lock-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_teacher_receives_403_and_cannot_modify_past_absence_or_notes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Ho_Chi_Minh'));
        [$teacher, $student, $lessonId] = $this->lessonFor('2026-10-14 09:00:00');
        DB::table('attendance_records')->insert(['lesson_id' => $lessonId, 'student_id' => $student->id, 'status' => 'unexcused', 'note' => 'Vắng không phép', 'recorded_by' => $teacher->id, 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $this->withHeaders($this->headersFor($teacher));

        $this->getJson('/api/lessons/'.$lessonId.'/attendance')->assertOk()->assertJsonPath('can_edit', false)->assertJsonPath('students.0.status', 'unexcused');
        $this->postJson('/api/lessons/'.$lessonId.'/attendance', ['can_edit' => true, 'starts_at' => '2026-10-15 09:00:00', 'records' => [['student_id' => $student->id, 'status' => 'present', 'note' => 'Thay đổi trái phép']]])
            ->assertForbidden()->assertJsonPath('message', 'Điểm danh của ngày đã qua đã được khóa. Vui lòng liên hệ quản trị viên nếu cần điều chỉnh.');

        $this->assertDatabaseHas('attendance_records', ['lesson_id' => $lessonId, 'student_id' => $student->id, 'status' => 'unexcused', 'note' => 'Vắng không phép', 'updated_at' => '2026-10-14 12:00:00']);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_teacher_receives_403_when_recording_a_past_lesson_for_the_first_time(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Ho_Chi_Minh'));
        [$teacher, $student, $lessonId] = $this->lessonFor('2026-10-14 23:30:00');
        $this->withHeaders($this->headersFor($teacher))->postJson('/api/lessons/'.$lessonId.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'excused', 'note' => 'Xin phép']]])->assertForbidden();

        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_teacher_can_update_today_after_the_lesson_has_ended(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 18:00:00', 'Asia/Ho_Chi_Minh'));
        [$teacher, $student, $lessonId] = $this->lessonFor('2026-10-15 09:00:00');
        $this->withHeaders($this->headersFor($teacher));
        $this->getJson('/api/lessons/'.$lessonId.'/attendance')->assertOk()->assertJsonPath('can_edit', true)->assertJsonPath('editable_until', '2026-10-16T00:00:00+07:00');
        $this->postJson('/api/lessons/'.$lessonId.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'late', 'note' => 'Đến muộn']]])->assertOk();

        $this->assertDatabaseHas('attendance_records', ['lesson_id' => $lessonId, 'student_id' => $student->id, 'status' => 'late', 'note' => 'Đến muộn']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'attendance', 'entity_id' => $lessonId]);
    }

    public function test_teacher_access_expires_at_school_midnight_even_with_a_previously_open_page(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14T16:59:59Z'));
        [$teacher, $student, $lessonId] = $this->lessonFor('2026-10-14 09:00:00');
        $this->withHeaders($this->headersFor($teacher));
        $this->getJson('/api/lessons/'.$lessonId.'/attendance')->assertOk()->assertJsonPath('can_edit', true);
        $this->postJson('/api/lessons/'.$lessonId.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'excused', 'note' => 'Đã ghi nhận']]])->assertOk();

        $this->travelTo(CarbonImmutable::parse('2026-10-14T17:00:00Z'));
        $this->getJson('/api/lessons/'.$lessonId.'/attendance')->assertOk()->assertJsonPath('can_edit', false);
        $this->postJson('/api/lessons/'.$lessonId.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'present']]])->assertForbidden();
        $this->assertDatabaseHas('attendance_records', ['lesson_id' => $lessonId, 'student_id' => $student->id, 'status' => 'excused', 'note' => 'Đã ghi nhận']);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_admin_retains_audited_correction_access_for_past_dates(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Ho_Chi_Minh'));
        [, $student, $lessonId] = $this->lessonFor('2026-10-14 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withHeaders($this->headersFor($admin));
        $this->getJson('/api/lessons/'.$lessonId.'/attendance')->assertOk()->assertJsonPath('can_edit', true)->assertJsonPath('locked_reason', null);
        $this->postJson('/api/lessons/'.$lessonId.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'excused', 'note' => 'Đã xác minh giấy phép']]])->assertOk();

        $this->assertDatabaseHas('attendance_records', ['lesson_id' => $lessonId, 'student_id' => $student->id, 'status' => 'excused', 'recorded_by' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $admin->id, 'entity_type' => 'attendance', 'entity_id' => $lessonId]);
    }

    public function test_student_remains_read_only_and_receives_403_on_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Ho_Chi_Minh'));
        [, $student, $lessonId] = $this->lessonFor('2026-10-15 09:00:00');
        $this->withHeaders($this->headersFor($student));
        $this->getJson('/api/lessons/'.$lessonId.'/attendance')->assertOk()->assertJsonPath('can_edit', false)->assertJsonCount(1, 'students');
        $this->postJson('/api/lessons/'.$lessonId.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'present']]])->assertForbidden();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_date_permissions_do_not_expose_another_teachers_class(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Ho_Chi_Minh'));
        [, $student, $lessonId] = $this->lessonFor('2026-10-14 09:00:00');
        $other = User::factory()->create(['role' => 'teacher']);
        $this->withHeaders($this->headersFor($other));
        $this->getJson('/api/lessons/'.$lessonId.'/attendance')->assertNotFound();
        $this->postJson('/api/lessons/'.$lessonId.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'present']]])->assertNotFound();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    public function test_teacher_cannot_reopen_locked_attendance_by_moving_a_past_lesson(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Ho_Chi_Minh'));
        [$teacher, $student, $lessonId] = $this->lessonFor('2026-10-14 09:00:00');
        $lesson = (array) DB::table('lessons')->find($lessonId);
        $this->withHeaders($this->headersFor($teacher));
        $this->putJson('/api/lessons/'.$lessonId, [...$lesson, 'starts_at' => '2026-10-16 09:00:00', 'ends_at' => '2026-10-16 09:45:00'])
            ->assertForbidden()->assertJsonPath('message', 'Không thể đổi ngày của tiết học đã qua. Vui lòng liên hệ quản trị viên nếu cần điều chỉnh.');
        $this->assertDatabaseHas('lessons', ['id' => $lessonId, 'starts_at' => '2026-10-14 09:00:00']);
        $this->postJson('/api/lessons/'.$lessonId.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'present']]])->assertForbidden();
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_teacher_can_correct_past_lesson_details_without_changing_its_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Ho_Chi_Minh'));
        [$teacher, , $lessonId] = $this->lessonFor('2026-10-14 09:00:00');
        $lesson = (array) DB::table('lessons')->find($lessonId);
        $this->withHeaders($this->headersFor($teacher))->putJson('/api/lessons/'.$lessonId, [...$lesson, 'room' => '305'])->assertOk();
        $this->assertDatabaseHas('lessons', ['id' => $lessonId, 'starts_at' => '2026-10-14 09:00:00', 'room' => '305']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'lesson', 'entity_id' => $lessonId, 'actor_id' => $teacher->id]);
    }

    public function test_admin_can_correct_a_past_lessons_date(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00', 'Asia/Ho_Chi_Minh'));
        [, , $lessonId] = $this->lessonFor('2026-10-14 09:00:00');
        $lesson = (array) DB::table('lessons')->find($lessonId);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->withHeaders($this->headersFor($admin))->putJson('/api/lessons/'.$lessonId, [...$lesson, 'starts_at' => '2026-10-16 09:00:00', 'ends_at' => '2026-10-16 09:45:00'])->assertOk();
        $this->assertDatabaseHas('lessons', ['id' => $lessonId, 'starts_at' => '2026-10-16 09:00:00']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'lesson', 'entity_id' => $lessonId, 'actor_id' => $admin->id]);
    }
}
