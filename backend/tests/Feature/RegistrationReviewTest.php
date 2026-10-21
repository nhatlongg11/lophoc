<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class RegistrationReviewTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    private function pending(string $role = 'student'): User
    {
        $user = User::factory()->create(['role' => $role, 'active' => false, 'password' => 'Registration123!']);
        $user->forceFill(['registration_status' => 'pending'])->save();

        return $user;
    }

    public function test_registration_is_closed_by_default_and_existing_accounts_can_still_login(): void
    {
        $user = User::factory()->create(['role' => 'teacher', 'password' => 'ExistingPassword123!']);
        $this->getJson('/api/registration/status')->assertExactJson(['enabled' => false, 'approval_required' => true]);
        $token = $this->getJson('/api/csrf')->json('token');
        $this->withHeader('X-CSRF-TOKEN', $token);
        $this->postJson('/api/register', ['name' => 'New person', 'email' => 'new@example.test', 'password' => 'Registration123!', 'password_confirmation' => 'Registration123!', 'role' => 'teacher'])->assertForbidden();
        $this->assertDatabaseCount('users', 1);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'ExistingPassword123!'])->assertOk();
        $this->getJson('/api/me')->assertOk();
        $this->assertNull($user->fresh()->registration_status);
        config(['eduquest.public_registration_enabled' => true]);
        $this->getJson('/api/registration/status')->assertExactJson(['enabled' => true, 'approval_required' => true]);
    }

    public function test_admin_approval_is_recorded_and_cannot_be_replayed(): void
    {
        $user = $this->pending('teacher');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->eduAs($admin);
        $this->getJson('/api/registrations')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $user->id)->assertJsonMissingPath('0.password');
        $this->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'approved'])->assertOk();
        $this->assertTrue($user->fresh()->active);
        $this->assertSame('approved', $user->fresh()->registration_status);
        $this->assertSame($admin->id, $user->fresh()->registration_reviewed_by);
        $this->assertNotNull($user->fresh()->registration_reviewed_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration_approved', 'actor_id' => $admin->id, 'entity_id' => $user->id]);
        $this->getJson('/api/registrations')->assertExactJson([]);
        $this->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'rejected'])->assertNotFound();
        $this->assertTrue($user->fresh()->active);
    }

    public function test_rejected_registrations_cannot_login(): void
    {
        $user = $this->pending('family');
        $admin = User::factory()->create(['role' => 'admin']);
        $this->eduAs($admin);
        $this->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'rejected'])->assertOk();
        $this->assertFalse($user->fresh()->active);
        $this->assertSame('rejected', $user->fresh()->registration_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'registration_rejected', 'actor_id' => $admin->id, 'entity_id' => $user->id]);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Registration123!'])->assertForbidden();
        $this->assertDatabaseMissing('api_tokens', ['user_id' => $user->id]);
    }

    #[TestWith(['teacher'])]
    #[TestWith(['student'])]
    #[TestWith(['parent'])]
    public function test_only_admins_can_list_or_review_registrations(string $role): void
    {
        $user = $this->pending();
        $this->eduAs(User::factory()->create(['role' => $role]));
        $this->getJson('/api/registrations')->assertForbidden();
        $this->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'approved'])->assertForbidden();
        $this->assertFalse($user->fresh()->active);
        $this->assertSame('pending', $user->fresh()->registration_status);
    }

    public function test_review_requires_authentication_and_a_valid_decision(): void
    {
        $user = $this->pending();
        $this->getJson('/api/registrations')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer invalid')->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'approved'])->assertUnauthorized();
        $this->eduAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'admin'])->assertUnprocessable()->assertJsonValidationErrors('decision');
        $this->assertSame('pending', $user->fresh()->registration_status);
    }

    public function test_another_organization_cannot_list_or_approve_requests(): void
    {
        $user = $this->pending();
        $org = DB::table('organizations')->insertGetId(['name' => 'Other school', 'slug' => 'other-school']);
        $this->eduAs(User::factory()->create(['role' => 'admin', 'organization_id' => $org]));
        $this->getJson('/api/registrations')->assertExactJson([]);
        $this->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'approved', 'organization_id' => 1])->assertNotFound();
        $this->assertSame('pending', $user->fresh()->registration_status);
        $this->assertFalse($user->fresh()->active);
    }

    public function test_approval_rechecks_account_limits_but_rejection_remains_available(): void
    {
        $user = $this->pending();
        $this->eduAs(User::factory()->create(['role' => 'admin']));
        DB::table('organizations')->where('id', 1)->update(['max_students' => 0]);
        $this->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'approved'])->assertUnprocessable();
        $this->assertFalse($user->fresh()->active);
        $this->assertNull($user->fresh()->registration_reviewed_at);
        $this->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'rejected'])->assertOk();
    }

    public function test_pending_requests_cannot_be_activated_through_user_editing_or_invitations(): void
    {
        $user = $this->pending('teacher');
        $this->eduAs(User::factory()->create(['role' => 'admin']));
        $this->putJson('/api/users/'.$user->id, ['name' => $user->name, 'email' => $user->email, 'role' => 'admin', 'active' => true])->assertUnprocessable();
        $url = $this->postJson('/api/invitations', ['email' => $user->email, 'role' => 'teacher'])->assertCreated()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->postJson('/api/invitations/accept', ['token' => $query['invite'], 'name' => $user->name, 'password' => 'Invitation123!', 'password_confirmation' => 'Invitation123!'])->assertUnprocessable();
        $this->assertSame('pending', $user->fresh()->registration_status);
        $this->assertSame('teacher', $user->fresh()->role);
        $this->assertFalse($user->fresh()->active);
    }

    public function test_pending_status_blocks_tokens_and_login_even_if_active_is_incorrectly_enabled(): void
    {
        $user = $this->pending();
        $user->update(['active' => true]);
        $this->eduAs($user);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'Registration123!'])->assertForbidden();
    }
}
