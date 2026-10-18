<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunicationTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user, bool $parent = false): array
    {
        $token = 'communication-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'mode' => $parent ? 'parent' : 'account', 'parent_verified_until' => $parent ? now()->addMinutes(15) : null, 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_only_linked_parent_and_teacher_can_exchange_messages_and_read_announcements(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $parent = User::factory()->create(['role' => 'parent']);
        $other = User::factory()->create(['role' => 'parent']);
        $student = User::factory()->create(['role' => 'student']);
        $year = DB::table('school_years')->insertGetId(['name' => 'Year', 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31']);
        $subject = DB::table('subjects')->insertGetId(['name' => 'Math']);
        $studentId = $student->id;
        DB::table('teacher_students')->insert(['organization_id' => $teacher->organization_id, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
        DB::table('parent_student_links')->insert(['organization_id' => 1, 'parent_id' => $parent->id, 'student_id' => $student->id]);
        $this->withHeaders($this->headers($teacher));
        $this->postJson('/api/messages', ['recipient_id' => $other->id, 'body' => 'Blocked'])->assertNotFound();
        $message = $this->postJson('/api/messages', ['recipient_id' => $parent->id, 'body' => 'Hôm nay con học tốt.'])->assertCreated()->json('id');
        $this->postJson('/api/announcements', ['student_ids' => [$studentId], 'title' => 'Họp lớp', 'body' => 'Thứ sáu'])->assertCreated();
        $this->withHeaders($this->headers($parent, true));
        $this->getJson('/api/messages?peer_id='.$teacher->id)->assertOk()->assertJsonCount(1)->assertJsonPath('0.body', 'Hôm nay con học tốt.');
        $this->getJson('/api/announcements')->assertOk()->assertJsonCount(1);
        $this->assertNotNull(DB::table('messages')->where('id', $message)->value('read_at'));
        $this->withHeaders($this->headers($other, true));
        $this->getJson('/api/messages?peer_id='.$teacher->id)->assertNotFound();
        $this->getJson('/api/announcements')->assertOk()->assertExactJson([]);
        $this->withHeaders($this->headers($student))->postJson('/api/messages', ['recipient_id' => $teacher->id, 'body' => 'No parent access'])->assertForbidden();
    }
}
