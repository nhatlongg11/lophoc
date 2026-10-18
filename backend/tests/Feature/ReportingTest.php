<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportingTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_reports_return_null_rates_instead_of_fabricated_success(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $token = 'report-token';
        DB::table('api_tokens')->insert(['user_id' => $teacher->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);
        $this->withToken($token)->getJson('/api/reports')->assertOk()->assertJsonPath('student_count', 0)->assertJsonPath('completion_rate', null)->assertJsonPath('average_score', null)->assertJsonPath('attendance_rate', null)->assertJsonPath('game_accuracy', null);
    }

    public function test_reports_normalize_grades_and_respect_class_ownership(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'teacher']);
        $year = DB::table('school_years')->insertGetId(['name' => 'Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Toán']);
        $studentId = $student->id;
        DB::table('teacher_students')->insert(['organization_id' => $teacher->organization_id, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
        $assignment = DB::table('assignments')->insertGetId(['teacher_id' => $teacher->id, 'title' => 'Test', 'instructions' => 'Work', 'due_at' => now()->subHour(), 'published' => true, 'max_score' => 20]);
        DB::table('assignment_students')->insert(['organization_id' => 1, 'assignment_id' => $assignment, 'student_id' => $studentId]);
        DB::table('submissions')->insert(['assignment_id' => $assignment, 'student_id' => $student->id, 'status' => 'graded', 'score' => 10, 'submitted_at' => now()->subHours(2)]);
        foreach ([$teacher, $other] as $user) {
            DB::table('api_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'report-'.$user->id), 'expires_at' => now()->addHour()]);
        }
        $this->withToken('report-'.$teacher->id)->getJson('/api/reports?student_id='.$studentId)->assertOk()->assertJsonPath('completion_rate', 100)->assertJsonPath('average_score', 5)->assertJsonPath('attendance_rate', null);
        $this->withToken('report-'.$other->id)->getJson('/api/reports?student_id='.$studentId)->assertNotFound();
        $this->getJson('/api/reports')->assertOk()->assertJsonPath('student_count', 0);
    }
}
