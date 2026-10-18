<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class ParentAnalyticsTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    public function test_parent_sees_actual_subject_results_and_weekly_completion_without_other_children(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $assignment = $this->postJson('/api/assignments', ['title' => 'Fractions', 'subject_id' => $f['subject'], 'instructions' => 'Answer', 'max_score' => 10, 'due_at' => '2026-10-18 18:00:00', 'submission_type' => 'text', 'student_ids' => [$f['student']->id], 'published' => true])->assertCreated()->json('id');
        $this->putJson('/api/assignments/'.$assignment.'/questions', ['allowed_attempts' => 2, 'grade_policy' => 'highest', 'questions' => [['type' => 'single', 'prompt' => 'Half of 4?', 'options' => ['2', '3'], 'answer' => '2', 'points' => 1]]])->assertOk();
        $this->eduAs($f['student']);
        $attempt = $this->postJson('/api/assignments/'.$assignment.'/start')->assertOk()->json();
        $this->postJson('/api/attempts/'.$attempt['id'].'/submit', ['answers' => [$attempt['questions'][0]['id'] => '2']])->assertOk();
        $this->eduAs($f['parent']);
        $response = $this->getJson('/api/family/dashboard?student_id='.$f['student']->id)->assertOk()->assertJsonCount(1, 'children')->assertJsonPath('children.0.analytics.submitted_count', 1)->assertJsonPath('children.0.analytics.assigned_count', 1)->assertJsonPath('children.0.analytics.subjects.0.average_score_percent', 100)->assertJsonPath('children.0.analytics.weekly_trends.11.completion_rate', 100)->assertJsonPath('children.0.analytics.homework.0.completed', true)->assertJsonPath('children.0.analytics.activity_history.0.title', 'Fractions');
        $this->assertNull($response->json('children.0.analytics.weekly_trends.0.completion_rate'));
        $this->postJson('/api/attempts/'.$attempt['id'].'/grade', ['score' => 0])->assertForbidden();
        $this->eduAs($f['other']);
        $this->getJson('/api/family/dashboard?student_id='.$f['student']->id)->assertNotFound();
        $this->getJson('/api/family/dashboard')->assertJsonPath('children', []);
        $this->assertDatabaseHas('assignment_attempts', ['id' => $attempt['id'], 'score' => 10]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $f['parent']->id, 'action' => 'parent_records_viewed']);
    }

    public function test_empty_parent_analytics_has_no_fabricated_scores(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['parent']);
        $this->getJson('/api/family/dashboard')->assertOk()->assertJsonPath('children.0.analytics.subjects', [])->assertJsonPath('children.0.analytics.submitted_count', 0)->assertJsonPath('children.0.analytics.activity_history', []);
    }
}
