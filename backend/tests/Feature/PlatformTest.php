<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        $token = 'platform-'.$user->id;
        DB::table('api_tokens')->insertOrIgnore(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);

        return ['Authorization' => 'Bearer '.$token];
    }

    public function test_platform_admin_creates_organization_and_invites_admin_without_student_access(): void
    {
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->withHeaders($this->headers($super));
        $result = $this->postJson('/api/platform/organizations', ['name' => 'New school', 'slug' => 'new-school', 'admin_email' => 'school@example.test'])->assertCreated()->json();
        parse_str(parse_url($result['invitation_url'], PHP_URL_QUERY), $query);
        $this->postJson('/api/invitations/accept', ['token' => $query['invite'], 'name' => 'School Admin', 'password' => 'SafePassword123!', 'password_confirmation' => 'SafePassword123!'])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'school@example.test', 'role' => 'admin', 'organization_id' => $result['id']]);
        $this->getJson('/api/users')->assertForbidden();
        $this->getJson('/api/classes')->assertNotFound();
        $this->getJson('/api/platform')->assertOk()->assertJsonCount(2, 'organizations');
    }

    public function test_only_platform_admin_can_approve_plan_requests_and_limits_change_on_server(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $super = User::factory()->create(['role' => 'super_admin']);
        $this->withHeaders($this->headers($admin))->postJson('/api/billing/requests', ['plan' => 'teacher_pro'])->assertCreated();
        $id = DB::table('billing_requests')->value('id');
        $this->postJson('/api/platform/billing-requests/'.$id.'/review', ['approved' => true, 'reason' => 'Contract reviewed'])->assertForbidden();
        $this->assertDatabaseHas('organizations', ['id' => 1, 'plan' => 'free']);
        $this->withHeaders($this->headers($super))->postJson('/api/platform/billing-requests/'.$id.'/review', ['approved' => true, 'reason' => 'Contract reviewed'])->assertOk();
        $this->assertDatabaseHas('organizations', ['id' => 1, 'plan' => 'teacher_pro', 'max_students' => 200, 'max_teachers' => 5]);
        $this->postJson('/api/platform/billing-requests/'.$id.'/review', ['approved' => true, 'reason' => 'Again'])->assertNotFound();
    }
}
