<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "You're invited to Dinar": a one-time link to choose a password and activate the account. */
class InviteMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token, public string $inviter) {}

    public function via(User $user): array
    {
        return ['mail'];
    }

    public function toMail(User $user): MailMessage
    {
        $l = $user->locale;
        $url = rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $this->token, 'email' => $user->email, 'invite' => 1]);

        return (new MailMessage)
            ->subject(__('invites.subject', [], $l))
            ->greeting(__('invites.greeting', ['name' => $user->name], $l))
            ->line(__('invites.intro', ['inviter' => $this->inviter], $l))
            ->action(__('invites.action', [], $l), $url)
            ->line(__('invites.expire', ['days' => (int) round(config('auth.passwords.invites.expire') / 1440)], $l))
            ->line(__('invites.ignore', [], $l));
    }
}
