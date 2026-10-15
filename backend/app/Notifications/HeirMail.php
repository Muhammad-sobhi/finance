<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Every email of the heir system. `$locales` are the languages to include: the owner's
 * own language for owner mails; both Arabic and English for heirs and trusted contacts.
 * Owner mails also land in the in-app notification center.
 */
class HeirMail extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  array<string, string|int>  $params */
    public function __construct(
        public string $kind,           // warning | activated | confirmed | granted | confirm
        public array $params,
        public ?string $url,
        public array $locales,
        public bool $inApp = false,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->inApp ? ['mail', 'database'] : ['mail'];
    }

    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $primary = $this->locales[0];
        $mail = (new MailMessage)->subject(__("heir.{$this->kind}_subject", $this->params, $primary));
        foreach ($this->locales as $locale) {
            $mail->line(__("heir.{$this->kind}_body", $this->params, $locale));
        }
        if ($this->url) {
            $mail->action(__('heir.'.$this->action(), [], $primary), $this->url);
        }

        return $mail;
    }

    public function toArray(object $notifiable): array
    {
        $text = fn (string $l) => __("heir.{$this->kind}_body", $this->params, $l);

        return [
            'type' => 'heir', 'kind' => $this->kind, 'path' => '/heir',
            'title' => ['ar' => __("heir.{$this->kind}_subject", $this->params, 'ar'), 'en' => __("heir.{$this->kind}_subject", $this->params, 'en')],
            'text' => ['ar' => $text('ar'), 'en' => $text('en')],
        ];
    }

    private function action(): string
    {
        return match ($this->kind) {
            'warning' => 'check_in',
            'confirmed' => 'cancel',
            'granted' => 'set_password',
            'confirm' => 'confirm',
            default => 'check_in',
        };
    }
}
