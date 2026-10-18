<?php

namespace Tests\Feature;

use App\Jobs\DeliverNotificationEmail;
use App\Mail\LearningNotificationMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_opt_in_email_is_delivered_once_and_disabled_delivery_is_skipped(): void
    {
        Mail::fake();
        config(['eduquest.email_notifications' => true]);
        $user = User::factory()->create();
        DB::table('notification_preferences')->insert(['organization_id' => 1, 'user_id' => $user->id, 'email_enabled' => true]);
        $id = DB::table('notifications')->insertGetId(['user_id' => $user->id, 'title' => 'Test', 'body' => 'Private grades']);
        $job = new DeliverNotificationEmail($id);
        $job->handle();
        $this->assertNotNull(DB::table('notifications')->where('id', $id)->value('emailed_at'));
        $job->handle();
        $other = DB::table('notifications')->insertGetId(['user_id' => $user->id, 'title' => 'Test2', 'body' => 'Private']);
        config(['eduquest.email_notifications' => false]);
        (new DeliverNotificationEmail($other))->handle();
        $this->assertNull(DB::table('notifications')->where('id', $other)->value('emailed_at'));
        Mail::assertSent(LearningNotificationMail::class, 1);
        Mail::assertSent(LearningNotificationMail::class, fn ($mail) => $mail->hasTo($user->email));
    }
}
