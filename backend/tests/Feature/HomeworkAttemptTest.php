<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HomeworkAttemptTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'attempt-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function assignment(User $teacher, User $student): int
    {
        $year = DB::table('school_years')->insertGetId(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Toán']);
        $studentId = $student->id;
        DB::table('teacher_students')->insert(['organization_id' => $teacher->organization_id, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
        $this->withHeaders($this->headers($teacher));
        $id = $this->postJson('/api/assignments', ['title' => 'Bài kiểm tra', 'instructions' => 'Làm bài', 'max_score' => 10, 'due_at' => now()->addDay()->toDateTimeString(), 'submission_type' => 'text', 'student_ids' => [$studentId], 'published' => true])->assertCreated()->json('id');
        $this->putJson('/api/assignments/'.$id.'/questions', ['allowed_attempts' => 2, 'grade_policy' => 'highest', 'questions' => [['type' => 'single', 'prompt' => '2 + 2?', 'options' => ['3', '4'], 'answer' => '4', 'points' => 1], ['type' => 'fill_blank', 'prompt' => 'Viết từ mèo bằng tiếng Anh', 'options' => [], 'answer' => ['cat', 'a cat'], 'points' => 1]]])->assertOk();

        return $id;
    }

    public function test_autograding_retakes_history_and_duplicate_submission(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $id = $this->assignment($teacher, $student);
        $this->withHeaders($this->headers($student));
        $first = $this->postJson('/api/assignments/'.$id.'/start')->assertOk()->json();
        $this->assertArrayNotHasKey('answer', $first['questions'][0]);
        $q = array_column($first['questions'], 'id');
        $this->putJson('/api/attempts/'.$first['id'], ['answers' => [$q[0] => '4']])->assertOk();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('submissions', 0);
        $this->postJson('/api/assignments/'.$id.'/start')->assertOk()->assertJsonPath('id', $first['id']);
        $this->postJson('/api/attempts/'.$first['id'].'/submit', ['answers' => [$q[0] => '4', $q[1] => 'wrong']])->assertOk()->assertJsonPath('score', 5);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('submissions', 1);
        $this->postJson('/api/attempts/'.$first['id'].'/submit', ['answers' => []])->assertStatus(409);
        $second = $this->postJson('/api/assignments/'.$id.'/start')->assertOk()->json();
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('submissions', 1);
        $this->postJson('/api/attempts/'.$second['id'].'/submit', ['answers' => [$q[0] => '4', $q[1] => '  CAT  ']])->assertOk()->assertJsonPath('score', 10);
        $this->postJson('/api/assignments/'.$id.'/start')->assertUnprocessable();
        $this->getJson('/api/assignments/'.$id.'/attempts')->assertOk()->assertJsonCount(2);
        $this->assertDatabaseHas('assignment_attempts', ['id' => $first['id'], 'score' => 5]);
        $this->assertDatabaseHas('submissions', ['assignment_id' => $id, 'student_id' => $student->id, 'score' => 10]);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('submissions', 1);
        $this->withHeaders($this->headers($teacher))->getJson('/api/dashboard')->assertOk()->assertJsonPath('submissions', 1);
    }

    public function test_attempts_are_private_and_teacher_cannot_rewrite_live_questions(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $id = $this->assignment($teacher, $student);
        $this->withHeaders($this->headers($student));
        $attempt = $this->postJson('/api/assignments/'.$id.'/start')->assertOk()->json();
        $this->getJson('/api/assignments/'.$id.'/questions')->assertForbidden();
        $this->withHeaders($this->headers($other))->putJson('/api/attempts/'.$attempt['id'], ['answers' => []])->assertNotFound();
        $this->withHeaders($this->headers($teacher))->putJson('/api/assignments/'.$id.'/questions', ['allowed_attempts' => 2, 'grade_policy' => 'highest', 'questions' => [['type' => 'single', 'prompt' => 'Changed', 'options' => ['a'], 'answer' => 'a', 'points' => 1]]])->assertUnprocessable();
    }

    public function test_essay_requires_manual_grading_and_score_changes_require_reason(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $id = $this->assignment($teacher, $student);
        $this->withHeaders($this->headers($teacher))->putJson('/api/assignments/'.$id.'/questions', ['allowed_attempts' => 1, 'grade_policy' => 'first', 'questions' => [['type' => 'essay', 'prompt' => 'Viết đoạn văn', 'options' => [], 'answer' => null, 'points' => 10]]])->assertOk();
        $this->withHeaders($this->headers($student));
        $attempt = $this->postJson('/api/assignments/'.$id.'/start')->assertOk()->json();
        $questionId = $attempt['questions'][0]['id'];
        $this->postJson('/api/attempts/'.$attempt['id'].'/submit', ['answers' => [$questionId => 'Bài văn của em']])->assertOk()->assertJsonPath('status', 'submitted');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('submissions', 1);
        $this->withHeaders($this->headers($teacher))->postJson('/api/attempts/'.$attempt['id'].'/grade', ['score' => 8, 'feedback' => 'Bố cục rõ ràng'])->assertOk();
        $this->assertDatabaseHas('submissions', ['assignment_id' => $id, 'student_id' => $student->id, 'score' => 8, 'status' => 'graded']);
        $this->postJson('/api/attempts/'.$attempt['id'].'/grade', ['score' => 9, 'feedback' => 'Sửa điểm'])->assertUnprocessable();
        $this->postJson('/api/attempts/'.$attempt['id'].'/grade', ['score' => 9, 'feedback' => 'Sửa điểm', 'reason' => 'Bổ sung đánh giá theo tiêu chí'])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'attempt_graded', 'entity_id' => $attempt['id']]);
    }
}
