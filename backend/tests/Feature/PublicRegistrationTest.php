<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['eduquest.public_registration_enabled' => true]);
    }

    private function approve(User $user): void
    {
        $this->eduAs(User::factory()->create(['role' => 'admin']));
        $this->postJson('/api/registrations/'.$user->id.'/review', ['decision' => 'approved'])->assertOk();
        $this->withHeader('Authorization', '');
    }

    private function payload(string $role = 'student'): array
    {
        return ['name' => 'Nguyễn Như', 'email' => 'new@example.test', 'password' => 'Registration123!', 'password_confirmation' => 'Registration123!', 'role' => $role, ...($role === 'parent' ? ['parent_pin' => '629184'] : [])];
    }

    private function csrf(): void
    {
        $token = $this->getJson('/api/csrf')->assertOk()->json('token');
        $this->withHeader('X-CSRF-TOKEN', $token);
    }

    #[TestWith(['student'])]
    #[TestWith(['teacher'])]
    public function test_people_can_request_an_account_and_only_login_after_admin_approval(string $role): void
    {
        $this->csrf();
        $payload = [...$this->payload($role), 'email' => 'New@Example.Test', 'active' => true, 'registration_status' => 'approved', 'registration_reviewed_by' => 1, 'email_verified_at' => now()->toDateTimeString()];
        $this->postJson('/api/register', $payload)->assertCreated()->assertJsonPath('email', 'new@example.test')->assertJsonMissingPath('password');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame($role, $user->role);
        $this->assertSame(1, $user->organization_id);
        $this->assertFalse($user->active);
        $this->assertSame('pending', $user->registration_status);
        $this->assertNull($user->registration_reviewed_by);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'registration_requested']);
        $this->postJson('/api/login', ['email' => 'new@example.test', 'password' => $payload['password']])->assertForbidden();
        $this->assertDatabaseCount('api_tokens', 0);
        $this->approve($user);
        $this->postJson('/api/login', ['email' => 'new@example.test', 'password' => $payload['password']])->assertOk()->assertJsonPath('user.role', $role);
        $this->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id);
        $this->getJson('/api/organization')->assertForbidden();
        $this->getJson('/api/platform')->assertForbidden();
    }

    public function test_parent_registers_with_a_private_pin_and_cannot_access_unlinked_children(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $this->csrf();
        $payload = $this->payload('parent');
        $this->postJson('/api/register', $payload)->assertCreated();
        $parent = User::where('email', $payload['email'])->firstOrFail();
        $this->assertSame('family', $parent->role);
        $this->assertFalse($parent->active);
        $this->approve($parent);
        $this->assertTrue(Hash::check('629184', $parent->parent_pin_hash));
        $this->assertDatabaseCount('parent_student_links', 0);
        $this->postJson('/api/login', ['email' => $payload['email'], 'password' => $payload['password']])->assertOk()->assertJsonPath('user.role', 'family')->assertJsonMissingPath('user.parent_pin_hash');
        $this->csrf();
        $this->postJson('/api/family/select', ['mode' => 'parent', 'pin' => '629184'])->assertOk();
        $this->getJson('/api/family/dashboard')->assertOk()->assertJsonPath('children', []);
        $this->getJson('/api/family/dashboard?student_id='.$student->id)->assertNotFound();
    }

    #[TestWith(['admin'])]
    #[TestWith(['super_admin'])]
    #[TestWith(['family'])]
    public function test_public_registration_cannot_grant_arbitrary_roles(string $role): void
    {
        $this->csrf();
        $this->postJson('/api/register', $this->payload($role))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_public_registration_requires_csrf_and_valid_fields(): void
    {
        $this->postJson('/api/register', $this->payload())->assertStatus(419);
        $this->csrf();
        $this->postJson('/api/register', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password', 'role']);
        $this->postJson('/api/register', [...$this->payload(), 'password_confirmation' => 'Different123!'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/register', [...$this->payload(), 'email' => ['new@example.test']])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/register', [...$this->payload('parent'), 'parent_pin' => null])->assertUnprocessable()->assertJsonValidationErrors('parent_pin');
        $this->postJson('/api/register', [...$this->payload('parent'), 'parent_pin' => '123'])->assertUnprocessable()->assertJsonValidationErrors('parent_pin');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_registration_cannot_select_another_tenant_or_link_a_child(): void
    {
        $other = DB::table('organizations')->insertGetId(['name' => 'Other', 'slug' => 'other']);
        config(['eduquest.registration_organization_id' => $other]);
        $this->csrf();
        $this->postJson('/api/register', [...$this->payload(), 'organization_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('organization_id');
        $this->postJson('/api/register', [...$this->payload('parent'), 'student_id' => 1])->assertUnprocessable()->assertJsonValidationErrors('student_id');
        $this->postJson('/api/register', $this->payload())->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'new@example.test', 'organization_id' => $other]);
    }

    public function test_registration_never_overwrites_an_existing_email_even_with_different_case(): void
    {
        $existing = User::factory()->create(['email' => 'New@Example.Test', 'role' => 'admin', 'password' => 'OriginalPassword123!']);
        $this->csrf();
        $this->postJson('/api/register', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertSame(1, User::count());
        $this->assertSame('admin', $existing->fresh()->role);
        $this->assertTrue(Hash::check('OriginalPassword123!', $existing->fresh()->password));
    }

    public function test_closed_organizations_and_account_limits_are_enforced(): void
    {
        $this->csrf();
        DB::table('organizations')->where('id', 1)->update(['subscription_status' => 'suspended']);
        $this->postJson('/api/register', $this->payload())->assertUnprocessable();
        DB::table('organizations')->where('id', 1)->update(['subscription_status' => 'active', 'max_students' => 0, 'max_teachers' => 0]);
        $this->postJson('/api/register', $this->payload())->assertUnprocessable();
        $this->postJson('/api/register', $this->payload('teacher'))->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_public_registration_is_rate_limited(): void
    {
        $this->csrf();
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $this->postJson('/api/register', [])->assertUnprocessable();
        }
        $this->postJson('/api/register', $this->payload())->assertTooManyRequests();
        $this->assertDatabaseCount('users', 0);
    }
}
