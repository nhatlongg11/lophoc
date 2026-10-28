<?php

namespace Tests\Feature;

use App\ArcadeTemplates;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class SubjectClassificationTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    public function test_homework_and_games_filter_subjects_without_bypassing_recipients_or_tenants(): void
    {
        $f = $this->eduFixture();
        $english = DB::table('subjects')->insertGetId(['name' => 'English']);
        $this->eduAs($f['teacher']);
        $expected = [];
        foreach (['assignments', 'games'] as $resource) {
            foreach ([$f['subject'], $english, null] as $subject) {
                $payload = ['title' => 'Subject lesson', 'subject_id' => $subject, 'student_ids' => [$f['student']->id], 'instructions' => 'Read and answer', 'max_score' => 10, 'due_at' => now()->addDay()->toDateTimeString(), 'submission_type' => 'text', 'published' => true];
                if ($resource === 'games') {
                    $payload += ['type' => 'quiz', 'questions' => [['prompt' => '2 + 2?', 'options' => ['3', '4'], 'answer' => '4']]];
                }
                $expected[$resource][$subject ?? 'none'] = $this->postJson('/api/'.$resource, $payload)->assertCreated()->json('id');
            }
        }
        $this->eduAs(User::factory()->create(['role' => 'teacher']));
        $outsider = User::factory()->create(['role' => 'student']);
        $this->postJson('/api/assignments', ['title' => 'Private task', 'subject_id' => $f['subject'], 'student_ids' => [$outsider->id], 'instructions' => 'Private', 'max_score' => 10, 'due_at' => now()->addDay()->toDateTimeString(), 'submission_type' => 'text', 'published' => true])->assertCreated();
        $this->postJson('/api/games', ['title' => 'Private game', 'subject_id' => $f['subject'], 'student_ids' => [$outsider->id], 'type' => 'quiz', 'questions' => [['prompt' => 'Private', 'options' => ['A', 'B'], 'answer' => 'A']]])->assertCreated();
        foreach ([$f['teacher'], $f['student']] as $user) {
            $this->eduAs($user);
            foreach (['assignments', 'games'] as $resource) {
                $this->getJson('/api/'.$resource.'?subject_id='.$f['subject'])->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $expected[$resource][$f['subject']])->assertJsonPath('0.subject_name', 'Math');
                $this->getJson('/api/'.$resource.'?subject_id='.$english)->assertJsonCount(1)->assertJsonPath('0.id', $expected[$resource][$english]);
                $this->getJson('/api/'.$resource.'?unassigned=1')->assertJsonCount(1)->assertJsonPath('0.id', $expected[$resource]['none']);
                $this->getJson('/api/'.$resource)->assertJsonCount(3);
                $this->getJson('/api/'.$resource.'?subject_id=invalid')->assertUnprocessable();
            }
        }
        $org = DB::table('organizations')->insertGetId(['name' => 'Other', 'slug' => 'other']);
        $this->eduAs(User::factory()->create(['role' => 'admin', 'organization_id' => $org]));
        foreach (['assignments', 'games'] as $resource) {
            $this->getJson('/api/'.$resource.'?subject_id='.$f['subject'])->assertExactJson([]);
        }
    }

    public function test_arcade_subject_filter_is_applied_before_pagination_and_filters_collections(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $english = DB::table('subjects')->insertGetId(['name' => 'English']);
        $content = ArcadeTemplates::all()[0]['content'];
        $rounds = [];
        foreach ([$f['subject'], $english] as $subject) {
            $content['subject_id'] = $subject;
            $rounds[$subject] = $this->postJson('/api/arcade/rounds', ['content' => $content, 'expected_revision' => 0])->assertCreated()->json('id');
            $this->postJson('/api/arcade/collections', ['title' => 'Subject collection', 'subject_id' => $subject, 'round_ids' => [$rounds[$subject]]])->assertCreated();
        }
        for ($i = 0; $i < 12; $i++) {
            DB::table('arcade_rounds')->insert(['organization_id' => 1, 'teacher_id' => $f['teacher']->id, 'title' => 'English '.$i, 'subject_id' => $english, 'grade' => '5', 'topic' => 'Words', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->getJson('/api/arcade/rounds?subject_id='.$f['subject'])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $rounds[$f['subject']])->assertJsonPath('data.0.subject_name', 'Math');
        $this->getJson('/api/arcade/rounds?subject_id='.$english)->assertJsonPath('total', 13)->assertJsonPath('last_page', 2);
        $this->getJson('/api/arcade/collections?subject_id='.$english)->assertJsonCount(1)->assertJsonPath('0.subject_id', $english);
        $this->getJson('/api/arcade/rounds?subject_id=invalid')->assertUnprocessable();
        $this->eduAs(User::factory()->create(['role' => 'teacher']));
        $this->getJson('/api/arcade/rounds?subject_id='.$f['subject'])->assertJsonPath('total', 0);
        $this->getJson('/api/arcade/collections?subject_id='.$english)->assertExactJson([]);
        $this->eduAs($f['student']);
        $this->getJson('/api/arcade/rounds?subject_id='.$f['subject'])->assertForbidden();
    }
}
