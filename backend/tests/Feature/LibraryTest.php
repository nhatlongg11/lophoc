<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LibraryTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'library-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_private_files_are_only_shared_explicitly_and_access_links_expire(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $student = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $year = DB::table('school_years')->insertGetId(['name' => 'Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Math']);
        $studentId = $student->id;
        DB::table('teacher_students')->insert(['organization_id' => $teacher->organization_id, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
        $this->withHeaders($this->headers($teacher));
        $id = $this->postJson('/api/library', ['file' => UploadedFile::fake()->create('lesson.pdf', 2, 'application/pdf'), 'folder' => 'Math'])->assertCreated()->json('id');
        $this->withHeaders($this->headers($student));
        $this->getJson('/api/library')->assertOk()->assertExactJson([]);
        $this->get('/api/files/'.$id)->assertNotFound();
        $this->withHeaders($this->headers($teacher))->putJson('/api/library/'.$id, ['name' => 'Bài học.pdf', 'folder' => 'Math', 'student_ids' => [$studentId]])->assertOk();
        $this->withHeaders($this->headers($student));
        $this->getJson('/api/library')->assertOk()->assertJsonCount(1);
        $url = $this->getJson('/api/files/'.$id.'/access')->assertOk()->json('url');
        $this->get($url)->assertOk();
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();
        $this->withHeaders($this->headers($other))->get('/api/files/'.$id)->assertNotFound();
    }

    public function test_storage_quota_and_dangerous_upload_validation(): void
    {
        Storage::fake('local');
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->withHeaders($this->headers($teacher));
        DB::table('organizations')->where('id', 1)->update(['storage_limit_bytes' => 1]);
        $this->postJson('/api/library', ['file' => UploadedFile::fake()->create('lesson.pdf', 2, 'application/pdf')])->assertUnprocessable();
        $this->postJson('/api/library', ['file' => UploadedFile::fake()->create('code.php', 1, 'application/x-php')])->assertUnprocessable();
        $this->assertDatabaseCount('attachments', 0);
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }
}
