<?php

namespace App\Jobs;

use App\Mail\LearningNotificationMail;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class DeliverNotificationEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public array $backoff = [10, 60, 300];

    public int $uniqueFor = 3600;

    public function __construct(public int $notificationId) {}

    public function uniqueId(): string
    {
        return (string) $this->notificationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (! config('eduquest.email_notifications')) {
            return;
        }
        Cache::lock('notification-email:'.$this->notificationId, 45)->block(5, function () {
            $notification = DB::table('notifications')->where('id', $this->notificationId)->whereNull('emailed_at')->first();
            if (! $notification || ! DB::table('notification_preferences')->where('organization_id', $notification->organization_id)->where('user_id', $notification->user_id)->where('email_enabled', true)->exists()) {
                return;
            }
            $user = DB::table('users')->where('organization_id', $notification->organization_id)->where('id', $notification->user_id)->where('active', true)->first();
            if (! $user) {
                return;
            }
            Mail::to($user->email)->send(new LearningNotificationMail(rtrim(config('app.url'), '/').'/app/'));
            DB::table('notifications')->where('id', $notification->id)->update(['emailed_at' => now()]);
        });
    }
}
