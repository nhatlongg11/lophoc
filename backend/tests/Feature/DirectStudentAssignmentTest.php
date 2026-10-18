<?php

namespace Tests\Feature;

use App\ArcadeTemplates;
use App\Models\User;
use Database\Seeders\LearningArcadeDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class DirectStudentAssignmentTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    private function homework(array $students): array
    {
        return ['title' => 'Direct homework', 'instructions' => 'Write an answer', 'max_score' => 10, 'due_at' => now()->addDay()->toDateTimeString(), 'submission_type' => 'text', 'student_ids' => $students, 'published' => true];
    }

    public function test_only_selected_students_and_their_parents_receive_the_homework_and_notifications(): void
    {
        $f = $this->eduFixture();
        $second = User::factory()->create(['role' => 'student']);
        $unselected = User::factory()->create(['role' => 'student']);
        $this->eduAs($f['teacher']);
        $id = $this->postJson('/api/assignments', $this->homework([$f['student']->id, $second->id]))->assertCreated()->json('id');
        foreach ([$f['student'], $second] as $student) {
            $this->eduAs($student);
            $this->getJson('/api/assignments')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $id);
            $this->getJson('/api/assignments/'.$id)->assertOk();
            $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'kind' => 'assignment']);
        }
        $this->eduAs($f['parent']);
        $this->getJson('/api/family/dashboard')->assertOk()->assertJsonPath('children.0.analytics.assigned_count', 1);
        $this->assertDatabaseHas('notifications', ['user_id' => $f['parent']->id, 'kind' => 'assignment']);
        $this->eduAs($unselected);
        $this->getJson('/api/assignments')->assertExactJson([]);
        $this->getJson('/api/assignments/'.$id)->assertNotFound();
        $this->postJson('/api/assignments/'.$id.'/submit', ['status' => 'submitted', 'answer' => 'Not assigned'])->assertNotFound();
        $this->assertDatabaseMissing('notifications', ['user_id' => $unselected->id]);
    }

    public function test_recipient_validation_rejects_empty_duplicate_inactive_wrong_role_and_other_organization_ids(): void
    {
        $f = $this->eduFixture();
        $inactive = User::factory()->create(['role' => 'student', 'active' => false]);
        $organization = DB::table('organizations')->insertGetId(['name' => 'Other', 'slug' => 'other']);
        $outsider = User::factory()->create(['organization_id' => $organization, 'role' => 'student']);
        $this->eduAs($f['teacher']);
        foreach ([[], [$f['student']->id, $f['student']->id], [$inactive->id], [$f['teacher']->id], [$outsider->id], [999999]] as $ids) {
            $this->postJson('/api/assignments', $this->homework($ids))->assertUnprocessable();
        }
        $this->assertDatabaseCount('assignments', 0);
        $this->assertDatabaseCount('notifications', 0);
        $this->eduAs($f['student']);
        $this->postJson('/api/assignments', $this->homework([$f['student']->id]))->assertForbidden();
    }

    public function test_changing_recipients_preserves_results_but_only_current_recipients_count_in_reports(): void
    {
        $f = $this->eduFixture();
        $remaining = User::factory()->create(['role' => 'student']);
        $new = User::factory()->create(['role' => 'student']);
        $this->eduAs($f['teacher']);
        $id = $this->postJson('/api/assignments', $this->homework([$f['student']->id, $remaining->id]))->assertCreated()->json('id');
        $this->eduAs($f['student']);
        $submission = $this->postJson('/api/assignments/'.$id.'/submit', ['status' => 'submitted', 'answer' => 'Keep my work'])->assertOk()->json('id');
        $this->eduAs($f['teacher']);
        $this->postJson('/api/submissions/'.$submission.'/grade', ['score' => 8, 'feedback' => 'Keep this score'])->assertOk();
        $this->putJson('/api/assignments/'.$id, $this->homework([$remaining->id, $new->id]))->assertOk();
        $this->assertDatabaseHas('submissions', ['id' => $submission, 'answer' => 'Keep my work', 'score' => 8]);
        $this->getJson('/api/submissions/'.$submission.'/history')->assertOk();
        $this->getJson('/api/dashboard')->assertJsonPath('submissions', 0);
        $this->getJson('/api/reports?to='.now()->addDays(2)->toDateString())->assertOk()->assertJsonPath('expected_submissions', 2)->assertJsonPath('submitted_count', 0)->assertJsonPath('completion_rate', 0);
        $this->assertDatabaseHas('notifications', ['user_id' => $new->id, 'kind' => 'assignment']);
        $this->eduAs($f['student']);
        $this->getJson('/api/assignments/'.$id)->assertNotFound();
        $this->eduAs($new);
        $this->getJson('/api/assignments/'.$id)->assertOk();
    }

    public function test_numeric_string_recipient_ids_work_for_announcements_recurrences_and_arcade(): void
    {
        $f = $this->eduFixture();
        $ids = [(string) $f['student']->id];
        $this->eduAs($f['teacher']);
        $this->postJson('/api/announcements', ['title' => 'Selected student', 'body' => 'Hello', 'student_ids' => $ids])->assertCreated();
        $this->postJson('/api/timetable/recurrences', ['title' => 'Weekly direct lesson', 'subject_id' => $f['subject'], 'teacher_id' => (string) $f['teacher']->id, 'student_ids' => $ids, 'starts_on' => '2026-10-19', 'ends_on' => '2026-10-19', 'weekdays' => [1], 'start_time' => '09:00', 'end_time' => '10:00'])->assertCreated();
        $content = [...ArcadeTemplates::all()[0]['content'], 'subject_id' => $f['subject']];
        $round = $this->postJson('/api/arcade/rounds', ['content' => $content, 'expected_revision' => 0])->assertCreated()->json('id');
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/assign', ['student_ids' => $ids, 'allowed_attempts' => 1])->assertCreated();
        $this->eduAs($f['student']);
        $this->getJson('/api/announcements')->assertOk()->assertJsonCount(1)->assertJsonPath('0.student_ids.0', $f['student']->id);
        $this->getJson('/api/lessons?from=2026-10-19&to=2026-10-19')->assertOk()->assertJsonCount(1)->assertJsonPath('0.student_ids.0', $f['student']->id);
        $this->getJson('/api/arcade/available')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/arcade/rounds/'.$round.'/start')->assertOk();
    }

    public function test_timetable_detects_student_teacher_and_room_conflicts_without_classes(): void
    {
        $f = $this->eduFixture();
        $admin = User::factory()->create(['role' => 'admin']);
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $otherStudent = User::factory()->create(['role' => 'student']);
        $payload = ['title' => 'Direct lesson', 'subject_id' => $f['subject'], 'teacher_id' => $f['teacher']->id, 'student_ids' => [$f['student']->id], 'starts_at' => '2026-10-17 09:00:00', 'ends_at' => '2026-10-17 10:00:00', 'room' => 'A', 'status' => 'scheduled'];
        $this->eduAs($admin);
        $lesson = $this->postJson('/api/lessons', $payload)->assertCreated()->json('id');
        $this->postJson('/api/lessons', [...$payload, 'teacher_id' => $otherTeacher->id, 'room' => 'B'])->assertUnprocessable()->assertJsonValidationErrors('starts_at');
        $this->postJson('/api/lessons', [...$payload, 'student_ids' => [$otherStudent->id], 'room' => 'B'])->assertUnprocessable();
        $this->postJson('/api/lessons', [...$payload, 'teacher_id' => $otherTeacher->id, 'student_ids' => [$otherStudent->id]])->assertUnprocessable();
        $this->postJson('/api/lessons', [...$payload, 'teacher_id' => $otherTeacher->id, 'student_ids' => [$otherStudent->id], 'room' => 'B'])->assertCreated();
        $this->postJson('/api/lessons/'.$lesson.'/attendance', ['records' => [['student_id' => $otherStudent->id, 'status' => 'present']]])->assertUnprocessable();
        $this->eduAs($f['student']);
        $this->getJson('/api/lessons?from=2026-10-17&to=2026-10-17')->assertJsonCount(1)->assertJsonPath('0.id', $lesson);
    }

    public function test_demo_seeding_is_repeatable_without_class_tables_or_columns(): void
    {
        $this->seed(LearningArcadeDemoSeeder::class);
        $counts = array_map(fn ($table) => DB::table($table)->count(), ['assignment_students', 'lesson_students', 'game_students', 'arcade_assignments']);
        $this->seed(LearningArcadeDemoSeeder::class);
        $this->assertSame($counts, array_map(fn ($table) => DB::table($table)->count(), ['assignment_students', 'lesson_students', 'game_students', 'arcade_assignments']));
        foreach ($counts as $count) {
            $this->assertGreaterThan(0, $count);
        }
        $this->assertFalse(Schema::hasTable('school_classes'));
        $this->assertFalse(Schema::hasColumn('lessons', 'class_id'));
        $this->assertFalse(Schema::hasColumn('organizations', 'max_classes'));
        $this->assertDatabaseCount('arcade_assignments', 4);
        $this->assertCount(6, json_decode(DB::table('arcade_assignments')->value('student_ids'), true));
    }
}
