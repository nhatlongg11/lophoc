<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class StudentConversationTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    public function test_student_context_messages_unread_counts_replies_and_private_attachments(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $sibling = User::factory()->create(['role' => 'student']);
        DB::table('class_memberships')->insert(['class_id' => $f['class'], 'student_id' => $sibling->id]);
        DB::table('parent_student_links')->insert(['organization_id' => 1, 'parent_id' => $f['parent']->id, 'student_id' => $sibling->id]);
        $this->eduAs($f['parent']);
        $this->postJson('/api/messages', ['recipient_id' => $f['teacher']->id, 'body' => 'Which child?'])->assertUnprocessable();
        $this->postJson('/api/messages', ['recipient_id' => $f['teacher']->id, 'student_id' => $f['student']->id, 'body' => '   '])->assertUnprocessable();
        $file = $this->postJson('/api/messages/attachments', ['peer_id' => $f['teacher']->id, 'student_id' => $f['student']->id, 'file' => UploadedFile::fake()->image('homework.png', 50, 50)])->assertCreated()->json('id');
        $this->postJson('/api/messages', ['recipient_id' => $f['teacher']->id, 'student_id' => $sibling->id, 'body' => 'Wrong attachment context', 'attachment_ids' => [$file]])->assertUnprocessable();
        $id = $this->postJson('/api/messages', ['recipient_id' => $f['teacher']->id, 'student_id' => $f['student']->id, 'body' => 'Please help with fractions.', 'attachment_ids' => [$file]])->assertCreated()->json('id');
        $this->assertDatabaseHas('notifications', ['user_id' => $f['teacher']->id, 'kind' => 'message']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'message_sent', 'entity_id' => $id]);
        $this->eduAs($f['teacher']);
        $this->getJson('/api/contacts')->assertJsonPath('0.unread_count', 1);
        $this->get('/api/files/'.$file)->assertOk();
        $this->getJson('/api/messages?peer_id='.$f['parent']->id.'&student_id='.$sibling->id)->assertExactJson([]);
        $this->getJson('/api/messages?peer_id='.$f['parent']->id.'&student_id='.$f['student']->id)->assertOk()->assertJsonPath('0.files.0.id', $file);
        $this->getJson('/api/contacts')->assertJsonPath('0.unread_count', 0);
        $this->postJson('/api/messages', ['recipient_id' => $f['parent']->id, 'student_id' => $f['student']->id, 'body' => 'We will practise tomorrow.'])->assertCreated();
        $this->eduAs($f['parent']);
        $this->getJson('/api/messages?peer_id='.$f['teacher']->id.'&student_id='.$f['student']->id)->assertJsonCount(2);
        $this->eduAs($f['other']);
        $this->getJson('/api/messages?peer_id='.$f['teacher']->id.'&student_id='.$f['student']->id)->assertNotFound();
        $this->get('/api/files/'.$file)->assertNotFound();
        $this->eduAs($f['student']);
        $this->getJson('/api/contacts')->assertForbidden();
        $this->assertDatabaseCount('messages', 2);
        Storage::disk('local')->assertExists(DB::table('attachments')->find($file)->path);
    }

    public function test_relationship_removal_revokes_history_and_attachment_access(): void
    {
        Storage::fake('local');
        $f = $this->eduFixture();
        $this->eduAs($f['teacher']);
        $this->postJson('/api/messages', ['recipient_id' => $f['parent']->id, 'body' => 'About learning'])->assertCreated();
        DB::table('parent_student_links')->where('parent_id', $f['parent']->id)->delete();
        $this->getJson('/api/messages?peer_id='.$f['parent']->id)->assertNotFound();
        $this->getJson('/api/contacts')->assertExactJson([]);
    }
}
