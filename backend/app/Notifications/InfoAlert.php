<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A one-off notice that isn't a due date (budget limits, unusual spending, a new sign-in…).
 * Text comes from lang/{ar,en}/notices.php and is stored in both languages for the UI.
 */
class InfoAlert extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, string|int|float|array{ar: string, en: string}>  $params  arrays hold per-language values */
    public function __construct(public string $kind, public array $params, public string $path, public bool $always = false) {}

    /** In-app always; email if enabled (or for security notices); push to subscribed devices. */
    public function via(User $user): array
    {
        return array_values(array_filter([
            'database',
            $user->email_alerts || $this->always ? 'mail' : null,
            $user->pushSubscriptions()->exists() ? WebPushChannel::class : null,
        ]));
    }

    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toArray(User $user): array
    {
        return [
            'type' => 'info', 'kind' => $this->kind, 'path' => $this->path,
            'title' => ['ar' => $this->line('title', 'ar'), 'en' => $this->line('title', 'en')],
            'text' => ['ar' => $this->line('body', 'ar'), 'en' => $this->line('body', 'en')],
        ];
    }

    public function toMail(User $user): MailMessage
    {
        return (new MailMessage)
            ->subject($this->line('title', $user->locale))
            ->line($this->line('body', $user->locale))
            ->action(__('alerts.open', [], $user->locale), rtrim(config('app.frontend_url'), '/').$this->path);
    }

    public function toWebPush(User $user, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->line('title', $user->locale))
            ->body($this->line('body', $user->locale))
            ->icon('/icon-192.png')
            ->tag("{$this->kind}-".md5(json_encode($this->params)))
            ->data(['url' => $this->path]);
    }

    private function line(string $part, string $locale): string
    {
        $params = array_map(fn ($v) => match (true) {
            is_array($v) => $v[$locale] ?? reset($v),
            is_float($v) || is_int($v) => number_format($v, $v == floor($v) ? 0 : 2),
            default => $v,
        }, $this->params);

        return __("notices.{$this->kind}.{$part}", $params, $locale);
    }
}
