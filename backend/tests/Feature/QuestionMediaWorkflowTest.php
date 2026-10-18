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

class QuestionMediaWorkflowTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    private function asUser(User $user): void
    {
        $token = 'media-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->withHeaders(['Authorization' => 'Bearer '.$token]);
    }

    public function test_teacher_upload_generates_playable_image_game_and_preserves_versions(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $outsider = User::factory()->create(['role' => 'student']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Science']);
        $year = DB::table('school_years')->insertGetId(['name' => '2026', 'starts_on' => '2026-01-01', 'ends_on' => '2027-01-01']);
        $class = DB::table('school_classes')->insertGetId(['name' => '5A', 'teacher_id' => $teacher->id, 'subject_id' => $subject, 'school_year_id' => $year]);
        DB::table('class_memberships')->insert(['class_id' => $class, 'student_id' => $student->id]);
        $this->asUser($teacher);
        $media = $this->post('/api/question-media', ['file' => UploadedFile::fake()->image('cat.png', 64, 64)], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $file = DB::table('attachments')->first();
        Storage::disk('local')->assertExists($file->path);
        $question = ['type' => 'image', 'prompt' => 'Which animal?', 'options' => ['Cat', 'Dog'], 'answer' => 'Cat', 'points' => 1, 'media' => ['id' => $media, 'alt' => 'A cat', 'caption' => 'Observe the animal'], 'choice_media' => ['Cat' => ['id' => $media, 'alt' => 'Cat choice']]];
        $bankPayload = ['name' => 'Animals', 'subject_id' => $subject, 'grade' => '5', 'topic' => 'Animals', 'difficulty' => 'easy', 'questions' => [$question]];
        $bank = $this->postJson('/api/question-banks', $bankPayload)->assertCreated()->json('id');
        $saved = $this->getJson('/api/question-banks/'.$bank)->assertOk()->assertJsonPath('questions.0.media.alt', 'A cat')->json();
        $config = ['bank_id' => $bank, 'engines' => ['quick_challenge', 'word_defense'], 'count' => 1, 'selection' => 'random', 'seed' => 'repeatable'];
        $generated = $this->postJson('/api/arcade/import-bank', $config)->assertOk()->assertJsonCount(1, 'activities')->assertJsonCount(1, 'rejected')->json();
        $again = $this->postJson('/api/arcade/import-bank', $config)->assertOk()->json();
        $this->assertSame($generated, $again);
        $content = ArcadeTemplates::all()[0]['content'];
        $content['subject_id'] = $subject;
        $content['activities'] = $generated['activities'];
        $content['randomize_questions'] = true;
        $round = $this->postJson('/api/arcade/rounds', ['content' => $content, 'expected_revision' => 0])->assertCreated()->json('id');
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $assignment = $this->postJson('/api/arcade/rounds/'.$round.'/assign', ['class_id' => $class, 'allowed_attempts' => 3])->assertCreated()->json('id');
        $this->asUser($student);
        $session = $this->postJson('/api/arcade/rounds/'.$round.'/start', ['assignment_id' => $assignment])->assertOk()->json();
        $activity = $generated['activities'][0];
        $session = $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => 'enter-image-01', 'revision' => 0, 'action' => ['kind' => 'enter', 'activity_id' => $activity['id']]])->assertOk()->assertJsonPath('current_activity.item.media.alt', 'A cat')->json();
        $this->get('/api/question-media/'.$media)->assertOk()->assertHeader('content-type', 'image/png');
        $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => 'answer-image-01', 'revision' => $session['revision'], 'action' => ['kind' => 'answer', 'activity_id' => $activity['id'], 'item_id' => $activity['items'][0]['id'], 'answer' => 'Cat']])->assertOk()->assertJsonPath('round_result.accuracy', 100);
        $this->assertDatabaseHas('arcade_results', ['student_id' => $student->id, 'accuracy' => 100]);
        $this->asUser($outsider);
        $this->getJson('/api/question-media/'.$media)->assertNotFound();
        $this->asUser($teacher);
        $oldVersion = $session['version_id'];
        $content['activities'][0]['items'][0]['accepted_answers'] = ['Dog'];
        $this->putJson('/api/arcade/rounds/'.$round, ['content' => $content, 'expected_revision' => 1])->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk();
        $this->postJson('/api/arcade/rounds/'.$round.'/publish')->assertOk();
        $this->assertDatabaseHas('arcade_sessions', ['id' => $session['id'], 'version_id' => $oldVersion, 'status' => 'completed']);
        $old = json_decode(DB::table('arcade_versions')->find($oldVersion)->content, true);
        $this->assertSame(['Cat'], $old['activities'][0]['items'][0]['accepted_answers']);
        $bankPayload['questions'] = $saved['questions'];
        $bankPayload['questions'][0]['answer'] = 'Dog';
        $bankPayload['expected_revision'] = 1;
        $this->putJson('/api/question-banks/'.$bank, $bankPayload)->assertOk();
        $this->getJson('/api/question-banks/'.$bank)->assertJsonPath('questions.0.id', $saved['questions'][0]['id']);
        $this->assertDatabaseCount('question_bank_versions', 2);
        $this->putJson('/api/question-banks/'.$bank, $bankPayload)->assertConflict();
    }

    public function test_rejects_invalid_uploads_and_foreign_media_references(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $other = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Math']);
        $this->postJson('/api/question-media')->assertUnauthorized();
        $this->asUser($student);
        $this->postJson('/api/question-media')->assertForbidden();
        $this->asUser($teacher);
        $this->post('/api/question-media', ['file' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/question-media', ['file' => UploadedFile::fake()->image('tiny.png', 1, 1)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/question-media', ['file' => UploadedFile::fake()->image('wide.png', 4097, 16)], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->post('/api/question-media', ['file' => UploadedFile::fake()->image('large.png', 40, 40)->size(5121)], ['Accept' => 'application/json'])->assertUnprocessable();
        $media = $this->post('/api/question-media', ['file' => UploadedFile::fake()->image('photo.jpg', 64, 64)], ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->asUser($other);
        $this->postJson('/api/question-banks', ['name' => 'Bank', 'subject_id' => $subject, 'difficulty' => 'easy', 'questions' => [['type' => 'image', 'prompt' => 'Image', 'options' => ['A', 'B'], 'answer' => 'A', 'points' => 1, 'media' => ['id' => $media, 'alt' => 'Image']]]])->assertUnprocessable();
        $this->getJson('/api/question-media/'.$media)->assertNotFound();
        $this->assertDatabaseCount('question_banks', 0);
    }

    public function test_homework_image_and_answer_images_survive_reload_and_grade_without_leaking_files(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $media = $this->postJson('/api/question-media', ['file' => UploadedFile::fake()->image('diagram.webp', 64, 64)])->assertCreated()->json('id');
        $assignment = $this->postJson('/api/assignments', ['title' => 'Diagram', 'instructions' => 'Answer the diagram', 'max_score' => 10, 'due_at' => now()->addDay()->toDateTimeString(), 'submission_type' => 'text', 'class_ids' => [$f['class']], 'published' => true])->assertCreated()->json('id');
        $payload = ['allowed_attempts' => 2, 'grade_policy' => 'highest', 'questions' => [['type' => 'image', 'prompt' => 'Which diagram?', 'options' => ['A', 'B'], 'answer' => 'A', 'points' => 1, 'media' => ['id' => $media, 'alt' => 'Science diagram', 'caption' => 'Observe'], 'choice_media' => ['B' => ['id' => $media, 'alt' => 'Diagram B']]]]];
        $this->putJson('/api/assignments/'.$assignment.'/questions', $payload)->assertOk();
        $this->getJson('/api/assignments/'.$assignment.'/questions')->assertJsonPath('0.choice_media.B.alt', 'Diagram B');
        $this->eduAs($f['student']);
        $attempt = $this->postJson('/api/assignments/'.$assignment.'/start')->assertOk()->assertJsonPath('questions.0.media.caption', 'Observe')->json();
        $this->get('/api/question-media/'.$media)->assertOk()->assertHeader('content-type', 'image/webp');
        $this->postJson('/api/assignments/'.$assignment.'/start')->assertJsonPath('id', $attempt['id'])->assertJsonPath('questions.0.media.alt', 'Science diagram');
        $this->postJson('/api/attempts/'.$attempt['id'].'/submit', ['answers' => [$attempt['questions'][0]['id'] => 'A']])->assertOk()->assertJsonPath('score', 10);
        $this->assertDatabaseHas('assignment_attempts', ['id' => $attempt['id'], 'score' => 10]);
        $outsider = User::factory()->create(['role' => 'student']);
        $this->eduAs($outsider);
        $this->getJson('/api/question-media/'.$media)->assertNotFound();
    }

    public function test_production_uploads_require_persistent_object_storage(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->asUser($teacher);
        $csrf = $this->getJson('/api/csrf')->json('token');
        $this->withSession(['eduquest_token' => 'media-'.$teacher->id]);
        $this->withHeaders(['X-CSRF-TOKEN' => $csrf]);
        $this->app['env'] = 'production';
        $this->postJson('/api/question-media', ['file' => UploadedFile::fake()->image('photo.png', 40, 40)])->assertServiceUnavailable();
        $this->assertDatabaseCount('attachments', 0);
        $this->app['env'] = 'testing';
    }

    public function test_image_labels_generate_meaningful_matching_and_memory_but_reject_word_defense(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $questions = [];
        foreach (['Cat', 'Dog', 'Bird', 'Fish'] as $label) {
            $media = $this->postJson('/api/question-media', ['file' => UploadedFile::fake()->image($label.'.png', 32, 32)])->assertCreated()->json('id');
            $questions[] = ['type' => 'image', 'prompt' => 'Identify the object', 'options' => [$label, 'Other'], 'answer' => $label, 'points' => 1, 'media' => ['id' => $media, 'alt' => $label.' illustration']];
        }
        $bank = $this->postJson('/api/question-banks', ['name' => 'Animals', 'subject_id' => $f['subject'], 'topic' => 'Animals', 'difficulty' => 'easy', 'questions' => $questions])->assertCreated()->json('id');
        $generated = $this->postJson('/api/arcade/import-bank', ['bank_id' => $bank, 'engines' => ['matching', 'memory_flip', 'word_defense'], 'count' => 4, 'seed' => 'images'])->assertOk()->assertJsonCount(2, 'activities')->assertJsonCount(4, 'rejected')->json();
        $content = ArcadeTemplates::all()[0]['content'];
        $content['subject_id'] = $f['subject'];
        $content['activities'] = $generated['activities'];
        $round = $this->postJson('/api/arcade/rounds', ['content' => $content, 'expected_revision' => 0])->assertCreated()->json('id');
        $preview = $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk()->json();
        $activity = $generated['activities'][0];
        $session = $this->postJson('/api/arcade/sessions/'.$preview['id'].'/actions', ['event_key' => 'image-pair-enter', 'revision' => 0, 'action' => ['kind' => 'enter', 'activity_id' => $activity['id']]])->assertOk()->json();
        foreach ($activity['pairs'] as $pair) {
            $cards = collect($session['current_activity']['cards']);
            $left = $cards->first(fn ($card) => ($card['media']['id'] ?? null) === $pair['left_media']['id']);
            $right = $cards->first(fn ($card) => $card['side'] === 'right' && $card['text'] === $pair['right']);
            $session = $this->postJson('/api/arcade/sessions/'.$preview['id'].'/actions', ['event_key' => 'image-pair-'.$left['id'], 'revision' => $session['revision'], 'action' => ['kind' => 'match', 'activity_id' => $activity['id'], 'left_id' => $left['id'], 'right_id' => $right['id']]])->assertOk()->json();
        }
        $this->assertSame(100, $session['current_activity']['state']['result']['accuracy']);
    }

    public function test_existing_https_question_images_are_retained_in_generated_activities(): void
    {
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $imageUrl = 'https://cdn.example.test/diagram.webp';
        $bank = $this->postJson('/api/question-banks', ['name' => 'Existing image content', 'subject_id' => $f['subject'], 'difficulty' => 'easy', 'questions' => [['type' => 'image', 'prompt' => 'Which diagram?', 'options' => ['A', 'B'], 'answer' => 'A', 'points' => 1, 'media_url' => $imageUrl]]])->assertCreated()->json('id');
        $generated = $this->postJson('/api/arcade/import-bank', ['bank_id' => $bank, 'engines' => ['quick_challenge'], 'count' => 1])->assertOk()->assertJsonPath('activities.0.items.0.media_url', $imageUrl)->json();
        $content = ArcadeTemplates::all()[0]['content'];
        $content['subject_id'] = $f['subject'];
        $content['activities'] = $generated['activities'];
        $round = $this->postJson('/api/arcade/rounds', ['content' => $content, 'expected_revision' => 0])->assertCreated()->json('id');
        $session = $this->postJson('/api/arcade/rounds/'.$round.'/preview')->assertOk()->json();
        $this->postJson('/api/arcade/sessions/'.$session['id'].'/actions', ['event_key' => 'external-image-enter', 'revision' => 0, 'action' => ['kind' => 'enter', 'activity_id' => $generated['activities'][0]['id']]])->assertOk()->assertJsonPath('current_activity.item.media_url', $imageUrl);
    }
}
