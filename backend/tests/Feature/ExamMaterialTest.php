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

    public function test_exam_upload_assignment_preview_replace_and_archive_enforce_recipient_access(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $id = $this->postJson('/api/library', ['file' => UploadedFile::fake()->create('practice.pdf', 4, 'application/pdf'), 'name' => 'Midterm practice', 'description' => 'Fractions revision', 'subject_id' => $f['subject'], 'grade' => '5', 'semester' => 'I', 'exam_category' => 'midterm', 'visibility' => 'assigned', 'student_ids' => [$f['student']->id], 'download_allowed' => false])->assertCreated()->json('id');
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
        $this->putJson('/api/library/'.$id, ['name' => 'Steal', 'folder' => '', 'student_ids' => []])->assertForbidden();
        $this->eduAs($f['teacher']);
        $this->postJson('/api/library/'.$id.'/replace', ['file' => UploadedFile::fake()->image('revised.png', 40, 40)])->assertOk();
        $replacement = DB::table('attachments')->find($id)->current_file_id;
        Storage::disk('local')->assertExists($original->path);
        Storage::disk('local')->assertExists(DB::table('attachments')->find($replacement)->path);
        $this->assertDatabaseHas('material_students', ['attachment_id' => $id, 'student_id' => $f['student']->id]);
        $this->eduAs($f['student']);
        $this->get($url)->assertOk()->assertHeader('content-type', 'image/png');
        $this->get('/api/files/'.$replacement)->assertNotFound();
        $this->eduAs($f['teacher']);
        $this->putJson('/api/library/'.$id, ['name' => 'Midterm practice', 'folder' => '', 'student_ids' => [$f['student']->id], 'archive' => true])->assertOk();
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
        $id = $this->postJson('/api/library', ['file' => UploadedFile::fake()->create('test.docx', 4, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 'visibility' => 'private', 'student_ids' => [$f['student']->id]])->assertCreated()->json('id');
        $this->eduAs($f['student']);
        $this->getJson('/api/files/'.$id.'/access')->assertNotFound();
        $this->getJson('/api/library')->assertExactJson([]);
        $this->eduAs($f['teacher']);
        $this->putJson('/api/library/'.$id, ['name' => 'Test', 'folder' => '', 'student_ids' => [], 'subject_id' => 999999])->assertUnprocessable();
    }

    public function test_preview_links_work_on_the_public_https_origin_and_reject_tampering_or_expiry(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $id = $this->postJson('/api/library', ['file' => UploadedFile::fake()->image('cat.png', 40, 40)])->assertCreated()->json('id');
        $other = $this->postJson('/api/library', ['file' => UploadedFile::fake()->image('dog.png', 40, 40)])->assertCreated()->json('id');
        $url = $this->getJson('http://internal-api.test/api/files/'.$id.'/access')->assertOk()->json('url');
        $this->assertStringStartsWith('/api/file-preview/'.$id.'?', $url);
        $this->get('https://public-app.test'.$url)->assertOk()->assertHeader('content-type', 'image/png');
        $this->get(str_replace('/'.$id.'?', '/'.$other.'?', $url))->assertForbidden();
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
        $fresh = $this->getJson('/api/files/'.$id.'/access')->assertOk()->json('url');
        $this->get($fresh)->assertOk();
    }

    public function test_missing_stored_files_return_a_useful_error_instead_of_a_broken_preview(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $id = $this->postJson('/api/library', ['file' => UploadedFile::fake()->image('cat.png', 40, 40)])->assertCreated()->json('id');
        Storage::disk('local')->delete(DB::table('attachments')->where('id', $id)->value('path'));
        $url = $this->getJson('/api/files/'.$id.'/access')->assertOk()->json('url');
        $this->getJson($url)->assertNotFound()->assertJsonPath('message', 'Không tìm thấy tệp tài liệu trong kho lưu trữ. Vui lòng liên hệ người đăng tải.');
    }
}
