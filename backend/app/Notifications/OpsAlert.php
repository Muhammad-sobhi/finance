<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "Something on the server stopped" (or is working again), to the admins. Sent right away,
 * not through the queue, because the queue may be what stopped. Text in lang/{ar,en}/ops.php.
 */
class OpsAlert extends Notification
{
    /** @param  'backup'|'queue'|'scheduler'|'encryption'|'failed_jobs'  $check */
    public function __construct(public string $check, public bool $recovered = false, public array $params = []) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $l = $notifiable->locale ?? config('app.locale');
        $key = $this->recovered ? 'recovered' : $this->check;
        $params = ['part' => __("ops.parts.{$this->check}", [], $l), 'app' => config('app.name'), ...$this->params];

        return (new MailMessage)
            ->subject(__("ops.{$key}.title", $params, $l))
            ->line(__("ops.{$key}.body", $params, $l))
            ->line(__('ops.health', ['url' => rtrim(config('app.url'), '/').'/api/v1/health'], $l));
    }
}
