<?php

namespace Tests\Feature;

use App\ArcadeTemplates;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class ArcadeCollectionTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    public function test_collections_assign_published_versions_and_catalog_filters_match_stored_progress(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $content = ArcadeTemplates::all()[0]['content'];
        $content['subject_id'] = $f['subject'];
        $content['activities'] = [['id' => 'quiz', 'engine' => 'quick_challenge', 'title' => 'Math', 'topic' => 'Numbers', 'objective' => 'Count', 'difficulty' => 2, 'allow_hints' => true, 'show_explanations' => true, 'retry_limit' => 1, 'timed' => false, 'time_limit_seconds' => 180, 'items' => [['id' => 'q1', 'prompt' => '2+2?', 'answer_mode' => 'choice', 'choices' => ['3', '4'], 'accepted_answers' => ['4'], 'case_sensitive' => false, 'numeric' => false]]]];
        $round = $this->postJson('/api/arcade/rounds', ['content' => $content, 'expected_revision' => 0])->assertCreated()->json('id');
        $collection = $this->postJson('/api/arcade/collections', ['title' => 'Midterm Math', 'subject_id' => $f['subject'], 'round_ids' => [$round]])->assertCreated()->json('id');
        $this->postJson('/api/arcade/collections/'.$collection.'/assign', ['student_ids' => [$f['student']->id], 'allowed_attempts' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('arcade_assignments', 0);
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $assigned = $this->postJson('/api/arcade/collections/'.$collection.'/assign', ['student_ids' => [$f['student']->id], 'allowed_attempts' => 1])->assertCreated()->json('assignment_ids.0');
        $this->eduAs($f['student']);
        $this->getJson('/api/arcade/available?difficulty=2&completion=not_started')->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/arcade/available?difficulty=1')->assertJsonPath('total', 0);
        $session = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assigned])->assertOk()->json();
        $this->getJson('/api/arcade/available?completion=in_progress')->assertJsonPath('total', 1);
        $session = $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => 'collection-enter', 'revision' => 0, 'action' => ['kind' => 'enter', 'activity_id' => 'quiz']])->assertOk()->json();
        $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => 'collection-answer', 'revision' => 1, 'action' => ['kind' => 'answer', 'activity_id' => 'quiz', 'item_id' => 'q1', 'answer' => '4']])->assertOk();
        $this->getJson('/api/arcade/available?completion=completed')->assertJsonPath('total', 1)->assertJsonPath('data.0.best_accuracy', 100);
        $this->getJson('/api/arcade/available?completion=not_started')->assertJsonPath('total', 0);
        $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assigned])->assertUnprocessable();
        $this->eduAs($f['teacher']);
        $this->getJson('/api/arcade/collections')->assertJsonPath('0.completion_rate', 100)->assertJsonPath('0.completed_count', 1);
        $otherTeacher = User::factory()->create(['role' => 'teacher']);
        $this->eduAs($otherTeacher);
        $this->postJson('/api/arcade/collections/'.$collection.'/assign', ['student_ids' => [$f['student']->id], 'allowed_attempts' => 2])->assertNotFound();
        $this->getJson('/api/arcade/collections')->assertExactJson([]);
        $this->assertDatabaseHas('arcade_assignments', ['id' => $assigned, 'collection_id' => $collection]);
    }

    public function test_generator_keeps_all_selected_questions_and_balances_difficulty_reproducibly(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $questions = [];
        for ($i = 0; $i < 15; $i++) {
            $questions[] = ['type' => 'single', 'prompt' => 'Question '.$i, 'options' => ['A', 'B'], 'answer' => 'A', 'points' => 1, 'difficulty' => ['easy', 'medium', 'hard'][$i % 3]];
        }
        $bank = $this->postJson('/api/question-banks', ['name' => 'Bank', 'subject_id' => $f['subject'], 'difficulty' => 'medium', 'questions' => $questions])->assertCreated()->json('id');
        $config = ['bank_id' => $bank, 'engines' => ['quick_challenge'], 'count' => 15, 'selection' => 'balanced', 'seed' => 'fixed-seed'];
        $result = $this->postJson('/api/arcade/import-bank', $config)->assertOk()->assertJsonCount(15, 'activities.0.items')->json();
        $this->postJson('/api/arcade/import-bank', $config)->assertExactJson($result);
        $this->assertCount(15, array_unique($result['generation']['selected_question_ids']));
    }
}
