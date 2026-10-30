<?php

namespace Tests\Feature;

use App\ArcadeTemplates;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class GradeClassificationTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    private function homework(array $f, string $title, ?string $grade = null): int
    {
        return $this->postJson('/api/assignments', ['title' => $title, 'grade' => $grade, 'subject_id' => $f['subject'], 'student_ids' => [$f['student']->id], 'instructions' => 'Làm bài', 'max_score' => 10, 'due_at' => now()->addDay()->toDateTimeString(), 'published' => true, 'submission_type' => 'both'])->assertCreated()->json('id');
    }

    public function test_homework_and_games_filter_class_before_subject_and_keep_unclassified_content(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $ids = [];
        foreach (['3', '4', '5', null] as $grade) {
            $ids['assignments'][$grade ?? 'unclassified'] = $this->homework($f, 'Work '.($grade ?? 'old'), $grade);
            $ids['games'][$grade ?? 'unclassified'] = $this->postJson('/api/games', ['title' => 'Game '.($grade ?? 'old'), 'grade' => $grade, 'subject_id' => $f['subject'], 'student_ids' => [$f['student']->id], 'type' => 'quiz', 'questions' => [['prompt' => '2+2?', 'options' => ['3', '4'], 'answer' => '4']]])->assertCreated()->json('id');
        }
        foreach ([$f['teacher'], $f['student']] as $user) {
            $this->eduAs($user);
            foreach (['assignments', 'games'] as $resource) {
                foreach (['3', '4', '5', 'unclassified'] as $grade) {
                    $this->getJson('/api/'.$resource.'?grade='.$grade.'&subject_id='.$f['subject'])->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $ids[$resource][$grade]);
                }
            }
        }
    }

    public function test_classification_preserves_submitted_work_and_only_the_owner_or_admin_can_change_it(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $id = $this->homework($f, 'Old quiz');
        $this->putJson('/api/assignments/'.$id.'/questions', ['allowed_attempts' => 1, 'grade_policy' => 'first', 'release_answers' => true, 'questions' => [['type' => 'single', 'prompt' => '2+2?', 'options' => ['3', '4'], 'answer' => '4', 'points' => 1]]])->assertOk();
        $this->eduAs($f['student']);
        $attempt = $this->postJson('/api/assignments/'.$id.'/start')->assertOk()->json();
        $this->postJson('/api/attempts/'.$attempt['id'].'/submit', ['answers' => [$attempt['questions'][0]['id'] => '4']])->assertOk()->assertJsonPath('score', 10);
        $snapshot = DB::table('assignment_attempts')->find($attempt['id']);
        $this->patchJson('/api/assignments/'.$id.'/classification', ['grade' => '4', 'subject_id' => $f['subject']])->assertForbidden();
        $this->eduAs(User::factory()->create(['role' => 'teacher']));
        $this->patchJson('/api/assignments/'.$id.'/classification', ['grade' => '4', 'subject_id' => $f['subject']])->assertNotFound();
        $this->eduAs($f['teacher']);
        $this->patchJson('/api/assignments/'.$id.'/classification', ['grade' => '6', 'subject_id' => $f['subject']])->assertUnprocessable();
        $subject = DB::table('subjects')->insertGetId(['name' => 'English']);
        $this->patchJson('/api/assignments/'.$id.'/classification', ['grade' => '4', 'subject_id' => $subject, 'student_ids' => [], 'max_score' => 1])->assertOk();
        $this->assertDatabaseHas('assignments', ['id' => $id, 'grade' => '4', 'subject_id' => $subject, 'max_score' => 10]);
        $this->assertDatabaseHas('assignment_students', ['assignment_id' => $id, 'student_id' => $f['student']->id]);
        $this->assertSame($snapshot->question_snapshot, DB::table('assignment_attempts')->find($attempt['id'])->question_snapshot);
        $this->assertDatabaseHas('submissions', ['assignment_id' => $id, 'student_id' => $f['student']->id, 'score' => 10, 'status' => 'graded']);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $id, 'entity_type' => 'assignment', 'action' => 'classified']);
        $org = DB::table('organizations')->insertGetId(['name' => 'Other', 'slug' => 'other']);
        $this->eduAs(User::factory()->create(['role' => 'admin', 'organization_id' => $org]));
        $this->patchJson('/api/assignments/'.$id.'/classification', ['grade' => '5', 'subject_id' => null])->assertNotFound();
        $this->getJson('/api/assignments?grade=4')->assertExactJson([]);
    }

    public function test_arcade_students_filter_the_assigned_version_class_and_collections_cannot_mix_classes(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $content = ArcadeTemplates::all()[0]['content'];
        $content['subject_id'] = $f['subject'];
        $content['grade'] = '3';
        $round = $this->postJson('/api/arcade/rounds', ['content' => $content, 'expected_revision' => 0])->assertCreated()->json('id');
        $collection = $this->postJson('/api/arcade/collections', ['title' => 'Class 3', 'grade' => '3', 'subject_id' => $f['subject'], 'round_ids' => [$round]])->assertCreated()->json('id');
        $this->postJson('/api/arcade/collections', ['title' => 'Class 4', 'grade' => '4', 'subject_id' => $f['subject'], 'round_ids' => [$round]])->assertUnprocessable();
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $this->postJson('/api/arcade/collections/'.$collection.'/assign', ['student_ids' => [$f['student']->id], 'allowed_attempts' => 2])->assertCreated();
        $this->getJson('/api/arcade/collections?grade=3')->assertJsonCount(1);
        $this->getJson('/api/arcade/collections?grade=4')->assertExactJson([]);
        $this->getJson('/api/arcade/rounds?grade=3&subject_id='.$f['subject'])->assertJsonPath('total', 1);
        $content['grade'] = '5';
        $this->putJson('/api/arcade/rounds/'.$round, ['content' => $content, 'expected_revision' => 1])->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $this->postJson('/api/arcade/collections/'.$collection.'/assign', ['student_ids' => [$f['student']->id], 'allowed_attempts' => 2])->assertUnprocessable();
        $this->eduAs($f['student']);
        $this->getJson('/api/arcade/available?grade=3')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.grade', '3');
        $this->getJson('/api/arcade/available?grade=5')->assertJsonPath('total', 0);
        $this->getJson('/api/arcade/hub?grade=3')->assertJsonPath('subjects.0.round_count', 1);
        $this->getJson('/api/arcade/hub?grade=5')->assertJsonPath('subjects.0.round_count', 0);
    }

    public function test_legacy_games_can_be_classified_without_changing_questions_or_recipients(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $id = $this->postJson('/api/games', ['title' => 'Old game', 'subject_id' => $f['subject'], 'student_ids' => [$f['student']->id], 'type' => 'quiz', 'questions' => [['prompt' => '2+2?', 'options' => ['3', '4'], 'answer' => '4']]])->assertCreated()->json('id');
        $question = DB::table('questions')->where('game_id', $id)->first();
        $this->patchJson('/api/games/'.$id.'/classification', ['grade' => '5A', 'subject_id' => $f['subject']])->assertUnprocessable();
        $this->patchJson('/api/games/'.$id.'/classification', ['grade' => '5', 'subject_id' => $f['subject']])->assertOk();
        $this->assertDatabaseHas('games', ['id' => $id, 'grade' => '5']);
        $this->assertDatabaseHas('questions', ['id' => $question->id, 'game_id' => $id, 'answer' => $question->answer]);
        $this->assertDatabaseHas('game_students', ['game_id' => $id, 'student_id' => $f['student']->id]);
        $this->eduAs($f['student']);
        $this->getJson('/api/games?grade=5')->assertJsonCount(1)->assertJsonPath('0.id', $id);
        $this->patchJson('/api/games/'.$id.'/classification', ['grade' => '4', 'subject_id' => $f['subject']])->assertForbidden();
    }

    public function test_upgrade_backfills_only_unambiguous_class_titles_and_preserves_recipients(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $known = $this->homework($f, 'Bài tập lớp 5');
        $unknown = $this->homework($f, 'Bài tổng hợp');
        $ambiguous = $this->homework($f, 'Ôn lớp 3 và lớp 4');
        $invalid = $this->homework($f, 'Bài lớp 35');
        $migration = require database_path('migrations/2026_10_30_141456_add_grade_classification_to_learning_content.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('assignments', ['id' => $known, 'grade' => '5']);
        foreach ([$unknown, $ambiguous, $invalid] as $id) {
            $this->assertDatabaseHas('assignments', ['id' => $id, 'grade' => null]);
        }
        $this->assertDatabaseCount('assignment_students', 4);
        $this->assertDatabaseHas('assignment_students', ['assignment_id' => $known, 'student_id' => $f['student']->id]);
    }
}
