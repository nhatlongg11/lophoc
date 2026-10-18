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

    private function recipientFor(User $teacher, User $student): array
    {
        $subjectId = DB::table('subjects')->insertGetId(['organization_id' => $teacher->organization_id, 'name' => 'Toán học', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('teacher_students')->insertOrIgnore(['organization_id' => $teacher->organization_id, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);

        return [$student->id, $subjectId];
    }

    public function test_assignment_submission_grading_and_role_boundaries(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        [$studentId] = $this->recipientFor($teacher, $student);

        $this->getJson('/api/assignments')->assertUnauthorized();
        $this->withHeaders($this->headersFor($student))->postJson('/api/assignments', [])->assertForbidden();

        $payload = ['title' => 'Bài đại số', 'instructions' => 'Giải phương trình.', 'max_score' => 10, 'starts_at' => now()->subMinute()->toDateTimeString(), 'due_at' => now()->addDays(2)->toDateTimeString(), 'submission_type' => 'text', 'student_ids' => [$studentId], 'published' => true];
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
        [$studentId, $subjectId] = $this->recipientFor($teacher, $student);
        $this->withHeaders($this->headersFor($teacher));
        $lesson = ['student_ids' => [$studentId], 'subject_id' => $subjectId, 'teacher_id' => $teacher->id, 'title' => 'Tiết Toán', 'room' => '205', 'period' => 1, 'starts_at' => now()->addDay()->setTime(9, 0)->toDateTimeString(), 'ends_at' => now()->addDay()->setTime(9, 45)->toDateTimeString(), 'status' => 'scheduled'];
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
        [$studentId] = $this->recipientFor($teacher, $student);
        $gameId = DB::table('games')->insertGetId(['teacher_id' => $teacher->id, 'title' => 'Đố vui', 'type' => 'timed', 'graded' => false, 'time_limit_seconds' => 60, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('game_students')->insert(['organization_id' => 1, 'game_id' => $gameId, 'student_id' => $studentId]);
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
        [$studentId, $subjectId] = $this->recipientFor($teacher, $student);
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
        DB::table('assignment_students')->insert(['organization_id' => 1, 'assignment_id' => $assignmentId, 'student_id' => $studentId]);
        $lessonId = DB::table('lessons')->insertGetId([
            'subject_id' => $subjectId,
            'teacher_id' => $teacher->id,
            'title' => 'Tiết sắp tới',
            'starts_at' => now()->addHour(),
            'ends_at' => now()->addHours(2),
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('lesson_students')->insert(['organization_id' => 1, 'lesson_id' => $lessonId, 'student_id' => $studentId]);

        $this->artisan('learning:remind')->assertSuccessful();
        $this->artisan('learning:remind')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'kind' => 'assignment_due:'.$assignmentId]);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'kind' => 'lesson_soon:'.$lessonId]);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_teachers_can_assign_the_same_student_directly_without_enrollment(): void
    {
        $firstTeacher = User::factory()->create(['role' => 'teacher']);
        $secondTeacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $secondStudent = User::factory()->create(['role' => 'student']);
        $payload = ['title' => 'Direct homework', 'instructions' => 'Answer', 'max_score' => 10, 'due_at' => now()->addDay()->toDateTimeString(), 'submission_type' => 'text', 'student_ids' => [$student->id, $secondStudent->id], 'published' => true];
        $first = $this->withHeaders($this->headersFor($firstTeacher))->postJson('/api/assignments', $payload)->assertCreated()->json('id');
        $second = $this->withHeaders($this->headersFor($secondTeacher))->postJson('/api/assignments', [...$payload, 'student_ids' => [$student->id]])->assertCreated()->json('id');
        $this->assertDatabaseHas('teacher_students', ['teacher_id' => $firstTeacher->id, 'student_id' => $student->id]);
        $this->assertDatabaseHas('teacher_students', ['teacher_id' => $secondTeacher->id, 'student_id' => $student->id]);
        $this->withHeaders($this->headersFor($student))->getJson('/api/assignments')->assertOk()->assertJsonCount(2);
        $this->withHeaders($this->headersFor($secondStudent))->getJson('/api/assignments')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $first);
        $this->getJson('/api/assignments/'.$second)->assertNotFound();
        $this->withHeaders($this->headersFor($firstTeacher))->getJson('/api/classes')->assertNotFound();
    }

    public function test_teacher_cannot_change_another_teachers_recipients(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $otherStudent = User::factory()->create(['role' => 'student']);
        $payload = ['title' => 'Homework', 'instructions' => 'Answer', 'max_score' => 10, 'due_at' => now()->addDay()->toDateTimeString(), 'submission_type' => 'text', 'student_ids' => [$student->id], 'published' => true];
        $id = $this->withHeaders($this->headersFor($teacher))->postJson('/api/assignments', $payload)->assertCreated()->json('id');
        $this->withHeaders($this->headersFor($otherTeacher))->putJson('/api/assignments/'.$id, [...$payload, 'student_ids' => [$otherStudent->id]])->assertNotFound();
        $this->assertDatabaseHas('assignment_students', ['assignment_id' => $id, 'student_id' => $student->id]);
        $this->assertDatabaseMissing('assignment_students', ['assignment_id' => $id, 'student_id' => $otherStudent->id]);
    }
}
