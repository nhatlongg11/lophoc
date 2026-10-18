<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class ExamMaterialTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    public function test_exam_upload_assignment_preview_replace_and_archive_enforce_class_access(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $id = $this->postJson('/api/library', ['file' => UploadedFile::fake()->create('practice.pdf', 4, 'application/pdf'), 'name' => 'Midterm practice', 'description' => 'Fractions revision', 'subject_id' => $f['subject'], 'grade' => '5', 'semester' => 'I', 'exam_category' => 'midterm', 'visibility' => 'assigned', 'class_ids' => [$f['class']], 'download_allowed' => false])->assertCreated()->json('id');
        $original = DB::table('attachments')->find($id);
        Storage::disk('local')->assertExists($original->path);
        $this->eduAs($f['student']);
        $this->getJson('/api/library?exam_category=midterm&subject_id='.$f['subject'])->assertOk()->assertJsonCount(1)->assertJsonPath('0.description', 'Fractions revision');
        $this->getJson('/api/library?exam_category=final')->assertExactJson([]);
        $url = $this->getJson('/api/files/'.$id.'/access')->assertOk()->json('url');
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/api/files/'.$id)->assertForbidden();
        $this->eduAs($f['other']);
        $this->get($url)->assertNotFound();
        $this->putJson('/api/library/'.$id, ['name' => 'Steal', 'folder' => '', 'class_ids' => []])->assertForbidden();
        $this->eduAs($f['teacher']);
        $this->postJson('/api/library/'.$id.'/replace', ['file' => UploadedFile::fake()->image('revised.png', 40, 40)])->assertOk();
        $replacement = DB::table('attachments')->find($id)->current_file_id;
        Storage::disk('local')->assertExists($original->path);
        Storage::disk('local')->assertExists(DB::table('attachments')->find($replacement)->path);
        $this->assertDatabaseHas('material_permissions', ['attachment_id' => $id, 'class_id' => $f['class']]);
        $this->eduAs($f['student']);
        $this->get($url)->assertOk()->assertHeader('content-type', 'image/png');
        $this->get('/api/files/'.$replacement)->assertNotFound();
        $this->eduAs($f['teacher']);
        $this->putJson('/api/library/'.$id, ['name' => 'Midterm practice', 'folder' => '', 'class_ids' => [$f['class']], 'archive' => true])->assertOk();
        $this->eduAs($f['student']);
        $this->get($url)->assertNotFound();
        $this->getJson('/api/library')->assertExactJson([]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'material', 'entity_id' => $id, 'action' => 'file_replaced']);
    }

    public function test_private_visibility_denies_previously_shared_file_and_invalid_subject_is_rejected(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $id = $this->postJson('/api/library', ['file' => UploadedFile::fake()->create('test.docx', 4, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'visibility' => 'private', 'class_ids' => [$f['class']]])->assertCreated()->json('id');
        $this->eduAs($f['student']);
        $this->getJson('/api/files/'.$id.'/access')->assertNotFound();
        $this->getJson('/api/library')->assertExactJson([]);
        $this->eduAs($f['teacher']);
        $this->putJson('/api/library/'.$id, ['name' => 'Test', 'folder' => '', 'class_ids' => [], 'subject_id' => 999999])->assertUnprocessable();
    }
}
