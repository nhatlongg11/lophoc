<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AccountRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_uses_a_one_time_token_and_revokes_existing_sessions(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@example.test']);
        DB::table('api_tokens')->insert(['user_id' => $user->id, 'token_hash' => hash('sha256', 'old-token'), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        $csrf = $this->getJson('/api/csrf')->json('token');
        $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/api/forgot-password', ['email' => $user->email])->assertOk();
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword123!', 'password_confirmation' => 'NewPassword123!'];
        $this->postJson('/api/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('NewPassword123!', $user->fresh()->password));
        $this->assertDatabaseCount('api_tokens', 0);
        $this->postJson('/api/reset-password', $payload)->assertUnprocessable();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_unknown_email_does_not_disclose_account_existence(): void
    {
        Notification::fake();
        $csrf = $this->getJson('/api/csrf')->json('token');
        $this->withHeader('X-CSRF-TOKEN', $csrf)->postJson('/api/forgot-password', ['email' => 'unknown@example.test'])->assertOk()->assertJsonPath('message', 'Nếu email đã đăng ký, hướng dẫn đặt lại mật khẩu sẽ được gửi.');
        Notification::assertNothingSent();
    }
}
