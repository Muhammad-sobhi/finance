<?php

namespace App\Notifications;

use App\Models\User;
use App\Services\DueItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class DueAlert extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public DueItem $item, public string $stage, public int $days) {}

    /** In-app always; email if enabled; browser push to every subscribed device. */
    public function via(User $user): array
    {
        return array_values(array_filter([
            'database',
            $user->email_alerts ? 'mail' : null,
            $user->pushSubscriptions()->exists() ? WebPushChannel::class : null,
        ]));
    }

    /** In-app alerts are written immediately; only mail and push wait for the queue worker. */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toArray(User $user): array
    {
        // Structured fields let the UI re-render in the current language; text is a fallback.
        return [
            'kind' => $this->item->kind, 'item_id' => $this->item->id, 'name' => $this->item->name,
            'stage' => $this->stage, 'days' => $this->days, 'due_date' => $this->item->due->toDateString(),
            'amount' => $this->item->amount, 'currency' => $this->item->currency, 'path' => $this->item->path,
            'title' => $this->title($user), 'body' => $this->body($user),
        ];
    }

    public function toMail(User $user): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title($user).' — '.$this->subject($user))
            ->line($this->body($user))
            ->action(__('alerts.open', [], $user->locale), rtrim(config('app.frontend_url'), '/').$this->item->path);
    }

    public function toWebPush(User $user, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title($user))
            ->body($this->body($user))
            ->icon('/icon-192.png')
            ->tag("{$this->item->kind}-{$this->item->id}-{$this->stage}")
            ->data(['url' => $this->item->path]);
    }

    private function title(User $user): string
    {
        return __("alerts.title.{$this->stage}", [], $user->locale);
    }

    private function subject(User $user): string
    {
        return __("alerts.subject.{$this->item->kind}", ['name' => $this->item->name], $user->locale);
    }

    private function body(User $user): string
    {
        if ($this->item->currency === '') {
            return __('alerts.body_plain', [
                'subject' => $this->subject($user),
                'when' => __("alerts.when.{$this->stage}", ['days' => abs($this->days)], $user->locale),
            ], $user->locale);
        }

        return __('alerts.body', [
            'subject' => $this->subject($user),
            'amount' => number_format($this->item->amount, $this->item->amount == floor($this->item->amount) ? 0 : 2),
            'currency' => $this->item->currency,
            'when' => __("alerts.when.{$this->stage}", ['days' => abs($this->days)], $user->locale),
        ], $user->locale);
    }
}
