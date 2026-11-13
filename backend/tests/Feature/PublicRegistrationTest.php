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

    public function test_students_can_create_an_account_and_login_immediately(): void
    {
        $this->csrf();
        $payload = [...$this->payload('student'), 'email' => 'New@Example.Test', 'active' => false, 'registration_status' => 'pending', 'registration_reviewed_by' => 1, 'email_verified_at' => now()->toDateTimeString()];
        $this->postJson('/api/register', $payload)->assertCreated()->assertJsonPath('email', 'new@example.test')->assertJsonPath('status', 'active')->assertJsonPath('joined_class', false)->assertJsonMissingPath('password');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('student', $user->role);
        $this->assertSame(1, $user->organization_id);
        $this->assertTrue($user->active);
        $this->assertNull($user->registration_status);
        $this->assertNull($user->registration_reviewed_by);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $user->id, 'action' => 'registration_created']);
        $this->postJson('/api/login', ['email' => 'new@example.test', 'password' => $payload['password']])->assertOk()->assertJsonPath('user.role', 'student');
        $this->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id);
        $this->getJson('/api/organization')->assertForbidden();
        $this->getJson('/api/platform')->assertForbidden();
    }

    public function test_teachers_still_need_admin_approval_before_login(): void
    {
        $this->csrf();
        $payload = [...$this->payload('teacher'), 'email' => 'New@Example.Test', 'active' => true, 'registration_status' => 'approved', 'registration_reviewed_by' => 1, 'email_verified_at' => now()->toDateTimeString()];
        $this->postJson('/api/register', $payload)->assertCreated()->assertJsonPath('email', 'new@example.test')->assertJsonPath('status', 'pending')->assertJsonMissingPath('password');
        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame('teacher', $user->role);
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
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->postJson('/api/login', ['email' => 'new@example.test', 'password' => $payload['password']])->assertOk()->assertJsonPath('user.role', 'teacher');
        $this->getJson('/api/me')->assertOk()->assertJsonPath('id', $user->id);
        $this->getJson('/api/organization')->assertForbidden();
        $this->getJson('/api/platform')->assertForbidden();
    }

    public function test_students_join_a_teachers_class_with_a_code_and_are_added_immediately(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'join_code' => 'CLASS123']);
        $this->csrf();
        $this->postJson('/api/register', [...$this->payload('student'), 'join_code' => 'class123'])->assertCreated()->assertJsonPath('status', 'active')->assertJsonPath('joined_class', true);
        $student = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertTrue($student->active);
        $this->assertNull($student->registration_status);
        $this->assertDatabaseHas('teacher_students', ['organization_id' => 1, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
        $this->assertDatabaseHas('audit_logs', ['actor_id' => $student->id, 'action' => 'registration_joined_class']);
        $this->postJson('/api/login', ['email' => 'new@example.test', 'password' => 'Registration123!'])->assertOk()->assertJsonPath('user.role', 'student');
    }

    public function test_active_self_registered_students_cannot_be_overwritten_by_an_invitation(): void
    {
        $this->csrf();
        $this->postJson('/api/register', [...$this->payload('student'), 'email' => 'student@example.test'])->assertCreated();
        $this->eduAs(User::factory()->create(['role' => 'admin']));
        $url = $this->postJson('/api/invitations', ['email' => 'student@example.test', 'role' => 'student'])->assertCreated()->json('url');
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->withHeader('Authorization', '');
        $this->postJson('/api/invitations/accept', ['token' => $query['invite'], 'name' => 'Người khác', 'password' => 'Invitation123!', 'password_confirmation' => 'Invitation123!'])->assertUnprocessable();
        $student = User::where('email', 'student@example.test')->firstOrFail();
        $this->assertSame('Nguyễn Như', $student->name);
        $this->assertTrue(Hash::check('Registration123!', $student->password));
    }

    public function test_class_code_registers_the_student_in_the_teachers_organization_and_invalid_codes_are_rejected(): void
    {
        $other = DB::table('organizations')->insertGetId(['name' => 'Other', 'slug' => 'other-class-code']);
        $teacher = User::factory()->create(['role' => 'teacher', 'organization_id' => $other, 'join_code' => 'OTHER123']);
        $this->csrf();
        $this->postJson('/api/register', [...$this->payload('student'), 'join_code' => 'OTHER123'])->assertCreated()->assertJsonPath('status', 'active');
        $student = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame($other, $student->organization_id);
        $this->assertDatabaseHas('teacher_students', ['organization_id' => $other, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
        DB::table('organizations')->where('id', $other)->update(['max_students' => 1]);
        $this->postJson('/api/register', [...$this->payload('student'), 'email' => 'second@example.test', 'join_code' => 'OTHER123'])->assertUnprocessable();
        $this->postJson('/api/register', [...$this->payload('student'), 'email' => 'invalid@example.test', 'join_code' => 'NOPE'])->assertUnprocessable()->assertJsonValidationErrors('join_code');
        $this->assertDatabaseMissing('users', ['email' => 'invalid@example.test']);
    }

    public function test_inactive_teachers_cannot_receive_students_through_a_class_code(): void
    {
        User::factory()->create(['role' => 'teacher', 'active' => false, 'join_code' => 'INACTIVE']);
        $pending = User::factory()->create(['role' => 'teacher', 'active' => false, 'join_code' => 'PENDING1']);
        $pending->forceFill(['registration_status' => 'pending'])->save();
        $this->csrf();
        $this->postJson('/api/register', [...$this->payload('student'), 'join_code' => 'INACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('join_code');
        $this->postJson('/api/register', [...$this->payload('student'), 'join_code' => 'PENDING1'])->assertUnprocessable()->assertJsonValidationErrors('join_code');
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_an_invalid_code_is_not_silently_treated_as_no_code(): void
    {
        $this->csrf();
        $this->postJson('/api/register', [...$this->payload('student'), 'join_code' => '0'])->assertUnprocessable()->assertJsonValidationErrors('join_code');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_approved_teachers_can_receive_students_through_their_class_code(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'join_code' => 'APPROVED']);
        $teacher->forceFill(['registration_status' => 'approved'])->save();
        $this->csrf();
        $this->postJson('/api/register', [...$this->payload('student'), 'join_code' => 'APPROVED'])->assertCreated()->assertJsonPath('joined_class', true);
        $this->assertDatabaseHas('teacher_students', ['teacher_id' => $teacher->id, 'student_id' => User::where('email', 'new@example.test')->value('id')]);
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
