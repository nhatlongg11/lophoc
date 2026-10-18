<?php

namespace Tests\Feature;

use App\ArcadeTemplates;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class FeatureTenantIsolationTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    public function test_new_feature_endpoints_isolate_organizations_and_reject_foreign_references(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $media = $this->postJson('/api/question-media', ['file' => UploadedFile::fake()->image('picture.png', 40, 40)])->assertCreated()->json('id');
        $bank = $this->postJson('/api/question-banks', ['name' => 'Bank', 'subject_id' => $f['subject'], 'difficulty' => 'easy', 'questions' => [['type' => 'single', 'prompt' => 'Question', 'options' => ['A', 'B'], 'answer' => 'A', 'points' => 1]]])->assertCreated()->json('id');
        $file = $this->postJson('/api/library', ['file' => UploadedFile::fake()->create('exam.pdf', 1, 'application/pdf'), 'class_ids' => [$f['class']]])->assertCreated()->json('id');
        $content = ArcadeTemplates::all()[0]['content'];
        $content['subject_id'] = $f['subject'];
        $round = $this->postJson('/api/arcade/rounds', ['content' => $content, 'expected_revision' => 0])->assertCreated()->json('id');
        $collection = $this->postJson('/api/arcade/collections', ['title' => 'Collection', 'subject_id' => $f['subject'], 'round_ids' => [$round]])->assertCreated()->json('id');
        $this->postJson('/api/messages', ['recipient_id' => $f['parent']->id, 'body' => 'Private educational message'])->assertCreated();
        $org = DB::table('organizations')->insertGetId(['name' => 'Other organization', 'slug' => 'other']);
        $admin = User::factory()->create(['organization_id' => $org, 'role' => 'admin']);
        $subject = DB::table('subjects')->insertGetId(['organization_id' => $org, 'name' => 'Other subject']);
        $this->eduAs($admin);
        $this->getJson('/api/question-banks/'.$bank)->assertNotFound();
        $this->getJson('/api/question-media/'.$media)->assertNotFound();
        $this->getJson('/api/files/'.$file.'/access')->assertNotFound();
        $this->getJson('/api/library')->assertExactJson([]);
        $this->getJson('/api/timetable/recurrences')->assertExactJson([]);
        $this->getJson('/api/arcade/collections')->assertExactJson([]);
        $this->postJson('/api/arcade/collections/'.$collection.'/assign', ['class_id' => $f['class'], 'allowed_attempts' => 1])->assertNotFound();
        $this->getJson('/api/messages?peer_id='.$f['parent']->id.'&student_id='.$f['student']->id)->assertNotFound();
        $this->getJson('/api/attendance/report?class_id='.$f['class'])->assertNotFound();
        $this->postJson('/api/question-banks', ['name' => 'Foreign media', 'subject_id' => $subject, 'difficulty' => 'easy', 'questions' => [['type' => 'image', 'prompt' => 'Question', 'options' => ['A', 'B'], 'answer' => 'A', 'points' => 1, 'media' => ['id' => $media, 'alt' => 'Picture']]]])->assertUnprocessable();
        $this->assertDatabaseCount('question_banks', 1);
        $this->assertDatabaseCount('messages', 1);
    }
}
