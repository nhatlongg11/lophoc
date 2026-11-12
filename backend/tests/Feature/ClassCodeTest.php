<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
