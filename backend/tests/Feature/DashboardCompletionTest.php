<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_returns_401_without_authentication(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_only_official_submissions_count_and_work_reopens_when_resubmission_is_requested(): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $studentId = $this->recipientFor($teacher, $student);
        $assignmentId = $this->assignmentFor($teacher, [$studentId]);

        $this->assertCompletionCount($student, 0);
        $this->withHeaders($this->headersFor($student))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'draft', 'answer' => 'Đang làm bài.'])
            ->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('submitted_at', null);
        $this->assertCompletionCount($student, 0);
        $this->assertCompletionCount($teacher, 0);

        $this->withHeaders($this->headersFor($student))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'submitted', 'answer' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('answer');
        $this->assertCompletionCount($student, 0);

        $submissionId = $this->withHeaders($this->headersFor($student))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'submitted', 'answer' => 'Bài làm chính thức.'])
            ->assertOk()->assertJsonPath('status', 'submitted')->json('id');
        $this->assertDatabaseHas('submissions', ['id' => $submissionId, 'status' => 'submitted']);
        $this->assertCompletionCount($student, 1);
        $this->assertCompletionCount($teacher, 1);

        $this->withHeaders($this->headersFor($teacher))->postJson('/api/submissions/'.$submissionId.'/grade', ['request_resubmission' => true, 'feedback' => 'Cần bổ sung bài làm.'])
            ->assertOk()->assertJsonPath('status', 'resubmit');
        $this->assertDatabaseHas('submissions', ['id' => $submissionId, 'status' => 'resubmit']);
        $this->assertCompletionCount($student, 0);
        $this->assertCompletionCount($teacher, 0);

        $this->withHeaders($this->headersFor($student))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'draft', 'answer' => 'Đang sửa bài.'])
            ->assertOk()->assertJsonPath('submitted_at', null);
        $this->assertCompletionCount($student, 0);
        $this->withHeaders($this->headersFor($student))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'submitted', 'answer' => 'Bài làm đã sửa.'])
            ->assertOk()->assertJsonPath('id', $submissionId);
        $this->assertCompletionCount($student, 1);
        $this->assertCompletionCount($teacher, 1);

        $this->withHeaders($this->headersFor($teacher))->postJson('/api/submissions/'.$submissionId.'/grade', ['score' => 0, 'feedback' => 'Đã chấm.'])
            ->assertOk()->assertJsonPath('status', 'graded');
        $this->assertCompletionCount($student, 1);
        $this->assertCompletionCount($teacher, 1);
    }

    public function test_teacher_counts_each_direct_recipients_official_submission_once(): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $firstStudent = User::factory()->create(['role' => 'student']);
        $secondStudent = User::factory()->create(['role' => 'student']);
        $assignmentId = $this->assignmentFor($teacher, [$firstStudent->id, $secondStudent->id]);

        $this->withHeaders($this->headersFor($firstStudent))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'submitted', 'answer' => 'Bài của học sinh thứ nhất.'])->assertOk();
        $this->assertCompletionCount($teacher, 1);
        $this->assertCompletionCount($firstStudent, 1);
        $this->assertCompletionCount($secondStudent, 0);

        $this->withHeaders($this->headersFor($secondStudent))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'draft', 'answer' => 'Bản nháp.'])->assertOk();
        $this->assertCompletionCount($teacher, 1);
        $this->withHeaders($this->headersFor($secondStudent))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'submitted', 'answer' => 'Bài của học sinh thứ hai.'])->assertOk();
        $this->assertCompletionCount($teacher, 2);
        $this->assertCompletionCount($secondStudent, 1);
    }

    public function test_completion_count_excludes_unpublished_assignments(): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $studentId = $this->recipientFor($teacher, $student);
        $assignmentId = $this->assignmentFor($teacher, [$studentId]);
        $this->withHeaders($this->headersFor($student))->postJson('/api/assignments/'.$assignmentId.'/submit', ['status' => 'submitted', 'answer' => 'Bài làm.'])->assertOk();
        DB::table('assignments')->where('id', $assignmentId)->update(['published' => false]);

        $this->assertCompletionCount($student, 0, 0);
        $this->assertCompletionCount($teacher, 0, 0);
    }

    public function test_teacher_only_counts_submissions_to_their_own_assignments(): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $otherStudent = User::factory()->create(['role' => 'student']);
        $studentId = $this->recipientFor($teacher, $student);
        $otherStudentId = $this->recipientFor($otherTeacher, $otherStudent);
        $assignmentId = $this->assignmentFor($teacher, [$studentId]);
        $otherAssignmentId = $this->assignmentFor($otherTeacher, [$otherStudentId]);
        $this->withHeaders($this->headersFor($otherStudent))->postJson('/api/assignments/'.$otherAssignmentId.'/submit', ['status' => 'submitted', 'answer' => 'Bài giao bởi giáo viên khác.'])->assertOk();

        $this->assertCompletionCount($teacher, 0);
        $this->assertCompletionCount($otherTeacher, 1);
        $this->assertCompletionCount($student, 0);
        $this->assertCompletionCount($otherStudent, 1);
    }

    public function test_completion_count_is_isolated_between_organizations(): void
    {
        $this->freezeTime();
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $studentId = $this->recipientFor($teacher, $student);
        $this->assignmentFor($teacher, [$studentId]);
        $otherOrganization = DB::table('organizations')->insertGetId(['name' => 'Trung tâm khác', 'slug' => 'other-center']);
        $otherTeacher = User::factory()->create(['role' => 'teacher', 'organization_id' => $otherOrganization]);
        $otherStudent = User::factory()->create(['role' => 'student', 'organization_id' => $otherOrganization]);
        $otherStudentId = $this->recipientFor($otherTeacher, $otherStudent);
        $otherAssignmentId = $this->assignmentFor($otherTeacher, [$otherStudentId]);
        $this->withHeaders($this->headersFor($otherStudent))->postJson('/api/assignments/'.$otherAssignmentId.'/submit', ['status' => 'submitted', 'answer' => 'Bài của trung tâm khác.'])->assertOk();

        $this->assertCompletionCount($teacher, 0);
        $this->assertCompletionCount($student, 0);
        $this->assertCompletionCount($otherTeacher, 1);
    }

    private function headersFor(User $user): array
    {
        $token = 'dashboard-completion-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    private function recipientFor(User $teacher, User $student): int
    {
        DB::table('teacher_students')->insertOrIgnore(['organization_id' => $teacher->organization_id, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);

        return $student->id;
    }

    private function assignmentFor(User $teacher, array $studentIds): int
    {
        return $this->withHeaders($this->headersFor($teacher))->postJson('/api/assignments', ['title' => 'Bài tập cần hoàn thành', 'instructions' => 'Nộp bài làm chính thức.', 'max_score' => 10, 'starts_at' => now()->subHour()->toDateTimeString(), 'due_at' => now()->addDay()->toDateTimeString(), 'submission_type' => 'text', 'student_ids' => $studentIds, 'published' => true])
            ->assertCreated()->json('id');
    }

    private function assertCompletionCount(User $user, int $completed, int $assigned = 1): void
    {
        $this->withHeaders($this->headersFor($user))->getJson('/api/dashboard')
            ->assertOk()->assertJsonPath('assignments', $assigned)->assertJsonPath('submissions', $completed);
    }
}
