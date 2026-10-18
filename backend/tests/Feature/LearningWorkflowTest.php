<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LearningWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function headersFor(User $user): array
    {
        $token = 'token-'.$user->id.'-'.str_repeat('x', 30);
        DB::table('api_tokens')->insertOrIgnore(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function classFor(User $teacher, User $student): array
    {
        $yearId = DB::table('school_years')->insertGetId(['name' => '2026–2027', 'starts_on' => '2026-08-01', 'ends_on' => '2027-05-31', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $subjectId = DB::table('subjects')->insertGetId(['name' => 'Toán học', 'color' => '#397c78', 'created_at' => now(), 'updated_at' => now()]);
        $classId = DB::table('school_classes')->insertGetId(['name' => '8A2', 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'school_year_id' => $yearId, 'room' => '205', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('class_memberships')->insert(['class_id' => $classId, 'student_id' => $student->id, 'created_at' => now(), 'updated_at' => now()]);

        return [$classId, $subjectId];
    }

    public function test_assignment_submission_grading_and_role_boundaries(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        [$classId] = $this->classFor($teacher, $student);

        $this->getJson('/api/assignments')->assertUnauthorized();
        $this->withHeaders($this->headersFor($student))->postJson('/api/assignments', [])->assertForbidden();

        $payload = ['title' => 'Bài đại số', 'instructions' => 'Giải phương trình.', 'max_score' => 10, 'starts_at' => now()->subMinute()->toDateTimeString(), 'due_at' => now()->addDays(2)->toDateTimeString(), 'submission_type' => 'text', 'class_ids' => [$classId], 'published' => true];
        $created = $this->withHeaders($this->headersFor($teacher))->postJson('/api/assignments', $payload)->assertCreated()->json();
        $assignmentId = $created['id'];
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'kind' => 'assignment']);

        $this->withHeaders($this->headersFor($student))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'submitted'])->assertUnprocessable()->assertJsonValidationErrors('answer');
        $submitted = $this->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'submitted', 'answer' => 'x = 3'])->assertOk()->json();
        $this->withHeaders($this->headersFor($otherTeacher))->postJson('/api/submissions/'.$submitted['id'].'/grade', ['score' => 8])->assertNotFound();
        $this->withHeaders($this->headersFor($teacher))->postJson('/api/submissions/'.$submitted['id'].'/grade', ['score' => 8, 'feedback' => 'Làm tốt.'])->assertOk()->assertJsonPath('status', 'graded');
        $this->assertDatabaseHas('submissions', ['id' => $submitted['id'], 'score' => 8, 'status' => 'graded']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'submission', 'entity_id' => $submitted['id']]);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'kind' => 'grade']);
    }

    public function test_attendance_defaults_and_schedule_conflicts(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        [$classId, $subjectId] = $this->classFor($teacher, $student);
        $this->withHeaders($this->headersFor($teacher));
        $lesson = ['class_id' => $classId, 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'title' => 'Tiết Toán', 'room' => '205', 'period' => 1, 'starts_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(), 'ends_at' => now()->addDay()->setTime(9, 45)->toDateTimeString(), 'status' => 'scheduled'];
        $lessonId = $this->postJson('/api/lessons', $lesson)->assertCreated()->json('id');
        $this->postJson('/api/lessons', $lesson)->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->getJson('/api/lessons/'.$lessonId.'/attendance')->assertOk()->assertJsonPath('students.0.status', 'present');
        $this->postJson('/api/lessons/'.$lessonId.'/attendance', ['records' => [['student_id' => $student->id, 'status' => 'late', 'note' => 'Đến lúc 09:05']]])->assertOk();
        $this->assertDatabaseHas('attendance_records', ['lesson_id' => $lessonId, 'student_id' => $student->id, 'status' => 'late']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'attendance', 'entity_id' => $lessonId]);
    }

    public function test_student_cannot_read_answers_and_cannot_submit_game_twice(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        [$classId] = $this->classFor($teacher, $student);
        $gameId = DB::table('games')->insertGetId(['teacher_id' => $teacher->id, 'class_id' => $classId, 'title' => 'Đố vui', 'type' => 'timed', 'graded' => false, 'time_limit_seconds' => 60, 'created_at' => now(), 'updated_at' => now()]);
        $questionId = DB::table('questions')->insertGetId(['game_id' => $gameId, 'prompt' => '2 + 2 bằng mấy?', 'options' => json_encode(['3', '4']), 'answer' => json_encode('4'), 'topic' => 'Phép cộng', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeaders($this->headersFor($student));
        $response = $this->getJson('/api/games/'.$gameId)->assertOk();
        $this->assertArrayNotHasKey('answer', $response->json('questions.0'));
        $sessionId = $this->postJson('/api/games/'.$gameId.'/start')->assertOk()->json('session_id');
        $this->postJson('/api/games/'.$gameId.'/attempt', ['session_id' => $sessionId, 'answers' => [$questionId => '4']])->assertOk()->assertJsonPath('score', 10);
        $this->postJson('/api/games/'.$gameId.'/attempt', ['session_id' => $sessionId, 'answers' => [$questionId => '4']])->assertUnprocessable();

        $this->assertDatabaseCount('game_attempts', 1);
    }

    public function test_reminders_are_sent_once_for_upcoming_work_and_lessons(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        [$classId, $subjectId] = $this->classFor($teacher, $student);
        $assignmentId = DB::table('assignments')->insertGetId([
            'teacher_id' => $teacher->id,
            'title' => 'Bài sắp đến hạn',
            'instructions' => 'Làm bài.',
            'max_score' => 10,
            'submission_type' => 'text',
            'due_at' => now()->addHours(3),
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('assignment_classes')->insert(['assignment_id' => $assignmentId, 'class_id' => $classId]);
        $lessonId = DB::table('lessons')->insertGetId([
            'class_id' => $classId,
            'subject_id' => $subjectId,
            'teacher_id' => $teacher->id,
            'title' => 'Tiết sắp tới',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('learning:remind')->assertSuccessful();
        $this->artisan('learning:remind')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'kind' => 'assignment_due:'.$assignmentId]);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'kind' => 'lesson_soon:'.$lessonId]);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_student_must_be_transferred_instead_of_added_to_a_second_class(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $oldTeacher = User::factory()->create(['role' => 'teacher']);
        $newTeacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $availableStudent = User::factory()->create(['role' => 'student']);
        [$oldClassId, $subjectId] = $this->classFor($oldTeacher, $student);
        $yearId = DB::table('school_classes')->where('id', $oldClassId)->value('school_year_id');
        $newClassId = DB::table('school_classes')->insertGetId([
            'name' => '8A3',
            'subject_id' => $subjectId,
            'teacher_id' => $newTeacher->id,
            'school_year_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($this->headersFor($admin));
        $available = $this->getJson('/api/users?role=student&unassigned=1')->assertOk()->json();
        $this->assertEqualsCanonicalizing([$availableStudent->id], array_column($available, 'id'));
        $this->postJson('/api/classes/'.$newClassId.'/students', ['student_id' => $student->id])
            ->assertUnprocessable()->assertJsonValidationErrors('student_id');
        DB::table('class_memberships')->insert([
            'class_id' => $newClassId,
            'student_id' => $student->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/classes/'.$oldClassId.'/transfer', [
            'student_id' => $student->id,
            'target_class_id' => $newClassId,
        ])->assertOk();

        $this->assertDatabaseMissing('class_memberships', ['class_id' => $oldClassId, 'student_id' => $student->id]);
        $this->assertDatabaseHas('class_memberships', ['class_id' => $newClassId, 'student_id' => $student->id]);
        $this->assertSame(1, DB::table('class_memberships')->where('student_id', $student->id)->count());
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'kind' => 'transfer', 'body' => 'Em đã được chuyển từ 8A2 sang 8A3.']);
        $this->assertDatabaseHas('notifications', ['user_id' => $oldTeacher->id, 'kind' => 'transfer']);
        $this->assertDatabaseHas('notifications', ['user_id' => $newTeacher->id, 'kind' => 'transfer']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'transferred', 'entity_type' => 'class_membership']);

        $this->postJson('/api/classes/'.$oldClassId.'/students', ['student_id' => $availableStudent->id])->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $availableStudent->id, 'kind' => 'class']);
        $this->assertDatabaseHas('notifications', ['user_id' => $oldTeacher->id, 'kind' => 'class']);
    }

    public function test_teacher_cannot_transfer_student_into_another_teachers_class(): void
    {
        $oldTeacher = User::factory()->create(['role' => 'teacher']);
        $newTeacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        [$oldClassId, $subjectId] = $this->classFor($oldTeacher, $student);
        $yearId = DB::table('school_classes')->where('id', $oldClassId)->value('school_year_id');
        $newClassId = DB::table('school_classes')->insertGetId([
            'name' => '8A3',
            'subject_id' => $subjectId,
            'teacher_id' => $newTeacher->id,
            'school_year_id' => $yearId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withHeaders($this->headersFor($oldTeacher))
            ->postJson('/api/classes/'.$oldClassId.'/transfer', [
                'student_id' => $student->id,
                'target_class_id' => $newClassId,
            ])->assertNotFound();
        $this->assertDatabaseHas('class_memberships', ['class_id' => $oldClassId, 'student_id' => $student->id]);
    }
}
