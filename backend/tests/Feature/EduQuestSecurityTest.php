<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EduQuestSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'security-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_admin_cannot_list_or_update_another_organizations_users(): void
    {
        $otherOrg = DB::table('organizations')->insertGetId(['name' => 'Other', 'slug' => 'other', 'created_at' => now(), 'updated_at' => now()]);
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'student', 'organization_id' => $otherOrg]);
        $this->withHeaders($this->headers($admin));
        $rows = $this->getJson('/api/users')->assertOk()->json();
        $this->assertNotContains($other->id, array_column($rows, 'id'));
        $this->putJson('/api/users/'.$other->id, ['name' => 'Stolen', 'email' => $other->email, 'role' => 'student'])->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $other->id, 'name' => $other->name]);
    }

    public function test_family_needs_pin_and_can_only_select_linked_children(): void
    {
        $family = User::factory()->create(['role' => 'family', 'parent_pin_hash' => Hash::make('738291')]);
        $child = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        DB::table('parent_student_links')->insert(['organization_id' => 1, 'parent_id' => $family->id, 'student_id' => $child->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeaders($this->headers($family));
        $this->getJson('/api/family/dashboard')->assertForbidden();
        $this->postJson('/api/family/select', ['mode' => 'parent', 'pin' => '000000'])->assertUnprocessable();
        $this->postJson('/api/family/select', ['mode' => 'parent', 'pin' => '738291'])->assertOk()->assertJsonPath('user.role', 'parent');
        $this->getJson('/api/family/dashboard?student_id='.$other->id)->assertNotFound();
        $this->getJson('/api/family/dashboard')->assertOk()->assertJsonCount(1, 'children')->assertJsonPath('children.0.id', $child->id);
        $this->postJson('/api/family/select', ['mode' => 'student', 'student_id' => $other->id])->assertNotFound();
        $this->postJson('/api/family/select', ['mode' => 'student', 'student_id' => $child->id])->assertOk()->assertJsonPath('user.id', $child->id);
        $this->getJson('/api/family/dashboard')->assertForbidden();
        $this->postJson('/api/users', [])->assertForbidden();
    }

    public function test_parent_unlock_expires_and_pin_is_rate_limited(): void
    {
        $family = User::factory()->create(['role' => 'family', 'parent_pin_hash' => Hash::make('567890')]);
        $this->withHeaders($this->headers($family));
        $this->postJson('/api/family/select', ['mode' => 'parent', 'pin' => '567890'])->assertOk();
        $this->travel(16)->minutes();
        $this->getJson('/api/family/dashboard')->assertForbidden();
        foreach (range(1, 5) as $i) {
            $this->postJson('/api/family/select', ['mode' => 'parent', 'pin' => '123456'])->assertUnprocessable();
        }
        $this->postJson('/api/family/select', ['mode' => 'parent', 'pin' => '567890'])->assertStatus(429);
    }

    public function test_private_materials_are_not_visible_to_other_staff(): void
    {
        $owner = User::factory()->create(['role' => 'teacher']);
        $other = User::factory()->create(['role' => 'teacher']);
        $id = DB::table('attachments')->insertGetId(['organization_id' => 1, 'resource_owner_id' => $owner->id, 'name' => 'Private.pdf', 'path' => 'private.pdf', 'created_at' => now(), 'updated_at' => now()]);
        $this->withHeaders($this->headers($other));
        $this->getJson('/api/resources')->assertOk()->assertExactJson([]);
        $this->get('/api/files/'.$id)->assertNotFound();
    }

    public function test_browser_login_requires_csrf_and_does_not_return_a_bearer_token(): void
    {
        User::factory()->create(['email' => 'secure@example.test', 'password' => 'Password123!']);
        $this->postJson('/api/login', ['email' => 'secure@example.test', 'password' => 'Password123!'])->assertStatus(419);
        $csrf = $this->getJson('/api/csrf')->assertOk()->json('token');
        $result = $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/api/login', ['email' => 'secure@example.test', 'password' => 'Password123!'])->assertOk();
        $this->assertArrayNotHasKey('token', $result->json());
        $this->assertDatabaseCount('api_tokens', 1);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('email', 'secure@example.test');
    }

    public function test_student_subscription_limit_is_enforced_server_side(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        DB::table('organizations')->where('id', 1)->update(['max_students' => 0]);
        $this->withHeaders($this->headers($admin))->postJson('/api/users', ['name' => 'Over limit', 'email' => 'limit@example.test', 'role' => 'student', 'password' => 'Password123!'])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'limit@example.test']);
    }

    public function test_failed_logins_are_limited_without_blocking_successful_sign_ins(): void
    {
        $user = User::factory()->create(['email' => 'limited@example.test', 'password' => 'SafePassword123!']);
        foreach (range(1, 6) as $attempt) {
            $csrf = $this->getJson('/api/csrf')->json('token');
            $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/api/login', ['email' => $user->email, 'password' => 'SafePassword123!'])->assertOk();
        }
        $csrf = $this->getJson('/api/csrf')->json('token');
        $this->withHeader('X-CSRF-TOKEN', $csrf);
        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'SafePassword123!'])->assertStatus(429);
    }

    public function test_reactivating_an_account_enforces_limits_and_password_changes_revoke_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $student = User::factory()->create(['role' => 'student', 'active' => false]);
        $studentHeaders = $this->headers($student);
        $this->withHeaders($this->headers($admin));
        DB::table('organizations')->where('id', 1)->update(['max_students' => 0]);
        $payload = ['name' => $student->name, 'email' => $student->email, 'role' => 'student', 'active' => true];
        $this->putJson('/api/users/'.$student->id, $payload)->assertUnprocessable();
        DB::table('organizations')->where('id', 1)->update(['max_students' => 50]);
        $this->putJson('/api/users/'.$student->id, [...$payload, 'password' => 'ChangedPassword123!'])->assertOk();
        $this->assertDatabaseMissing('api_tokens', ['user_id' => $student->id]);
    }
}
