<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\EduQuestFixtures;
use Tests\TestCase;

class ClassCodeTest extends TestCase
{
    use EduQuestFixtures, RefreshDatabase;

    public function test_teachers_can_view_and_rotate_their_class_code(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->eduAs($teacher);
        $first = $this->getJson('/api/class-code')->assertOk()->assertJsonStructure(['code', 'url'])->json();
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $first['code']);
        $this->assertStringEndsWith('/app/?join='.$first['code'], $first['url']);
        $second = $this->postJson('/api/class-code/refresh')->assertOk()->assertJsonStructure(['code', 'url'])->json();
        $this->assertNotSame($first['code'], $second['code']);
        $this->assertSame($second['code'], $teacher->fresh()->join_code);
        $this->assertDatabaseHas('audit_logs', ['action' => 'class_code_refreshed', 'actor_id' => $teacher->id, 'entity_id' => $teacher->id]);
    }

    public function test_only_teachers_can_manage_a_class_code(): void
    {
        $this->getJson('/api/class-code')->assertUnauthorized();
        $this->postJson('/api/class-code/refresh')->assertUnauthorized();
        $this->eduAs(User::factory()->create(['role' => 'student']));
        $this->getJson('/api/class-code')->assertForbidden();
        $this->postJson('/api/class-code/refresh')->assertForbidden();
        $this->eduAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/class-code')->assertForbidden();
        $this->postJson('/api/class-code/refresh')->assertForbidden();
    }

    public function test_rotating_the_class_code_invalidates_the_old_code(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher']);
        $this->eduAs($teacher);
        $old = $this->getJson('/api/class-code')->json('code');
        $new = $this->postJson('/api/class-code/refresh')->json('code');
        $this->withHeader('Authorization', '');
        $token = $this->getJson('/api/csrf')->json('token');
        $this->withHeader('X-CSRF-TOKEN', $token);
        $payload = ['name' => 'Nguyễn Như', 'email' => 'new@example.test', 'password' => 'Registration123!', 'password_confirmation' => 'Registration123!', 'role' => 'student'];
        $this->postJson('/api/register', [...$payload, 'join_code' => $old])->assertUnprocessable()->assertJsonValidationErrors('join_code');
        $this->postJson('/api/register', [...$payload, 'join_code' => $new])->assertCreated()->assertJsonPath('joined_class', true);
    }

    public function test_each_teacher_has_a_separate_class_code(): void
    {
        $first = User::factory()->create(['role' => 'teacher']);
        $second = User::factory()->create(['role' => 'teacher']);
        $this->eduAs($first);
        $firstCode = $this->getJson('/api/class-code')->json('code');
        $this->eduAs($second);
        $secondCode = $this->getJson('/api/class-code')->json('code');
        $this->assertNotSame($firstCode, $secondCode);
    }

    public function test_students_can_join_and_list_classes_after_registration(): void
    {
        $teacher = User::factory()->create(['role' => 'teacher', 'join_code' => 'JOIN1234']);
        $teacher->forceFill(['registration_status' => 'approved'])->save();
        $student = User::factory()->create(['role' => 'student']);
        $this->eduAs($student);
        $this->getJson('/api/class-memberships')->assertOk()->assertExactJson([]);
        $this->postJson('/api/class-memberships', ['code' => 'join1234'])->assertCreated()->assertJsonPath('joined', true)->assertJsonPath('teacher.id', $teacher->id);
        $this->assertDatabaseHas('teacher_students', ['organization_id' => 1, 'teacher_id' => $teacher->id, 'student_id' => $student->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'class_joined', 'actor_id' => $student->id, 'entity_id' => $student->id]);
        $this->getJson('/api/class-memberships')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $teacher->id)->assertJsonPath('0.name', $teacher->name);
        $this->postJson('/api/class-memberships', ['code' => 'JOIN1234'])->assertOk()->assertJsonPath('joined', false);
    }

    public function test_students_cannot_join_invalid_or_other_organization_classes(): void
    {
        $other = DB::table('organizations')->insertGetId(['name' => 'Other', 'slug' => 'other-membership']);
        $foreign = User::factory()->create(['role' => 'teacher', 'organization_id' => $other, 'join_code' => 'FOREIGN1']);
        $inactive = User::factory()->create(['role' => 'teacher', 'active' => false, 'join_code' => 'INACTIVE']);
        $student = User::factory()->create(['role' => 'student']);
        $this->eduAs($student);
        $this->postJson('/api/class-memberships', ['code' => 'NOPE'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/class-memberships', ['code' => 'FOREIGN1'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/class-memberships', ['code' => 'INACTIVE'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertDatabaseMissing('teacher_students', ['teacher_id' => $foreign->id, 'student_id' => $student->id]);
        $this->assertDatabaseMissing('teacher_students', ['teacher_id' => $inactive->id, 'student_id' => $student->id]);
    }

    public function test_only_students_can_list_or_join_classes(): void
    {
        $this->getJson('/api/class-memberships')->assertUnauthorized();
        $this->postJson('/api/class-memberships', ['code' => 'ABC12345'])->assertUnauthorized();
        $this->eduAs(User::factory()->create(['role' => 'teacher']));
        $this->getJson('/api/class-memberships')->assertForbidden();
        $this->postJson('/api/class-memberships', ['code' => 'ABC12345'])->assertForbidden();
        $this->eduAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/class-memberships')->assertForbidden();
        $this->postJson('/api/class-memberships', ['code' => 'ABC12345'])->assertForbidden();
    }
}
