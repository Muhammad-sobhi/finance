<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Reset your password" email in the user's language, linking to the SPA's reset page. */
class ResetPasswordMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(User $user): array
    {
        return ['mail'];
    }

    public function toMail(User $user): MailMessage
    {
        $l = $user->locale;
        $url = rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query(['token' => $this->token, 'email' => $user->email]);
        $minutes = config('auth.passwords.users.expire');

        return (new MailMessage)
            ->subject(__('passwords.mail_subject', [], $l))
            ->line(__('passwords.mail_intro', [], $l))
            ->action(__('passwords.mail_action', [], $l), $url)
            ->line(__('passwords.mail_expire', ['minutes' => $minutes], $l))
            ->line(__('passwords.mail_ignore', [], $l));
    }
}
