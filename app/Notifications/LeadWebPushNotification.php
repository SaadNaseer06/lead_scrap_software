<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class LeadWebPushNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $body,
        public ?string $title = null,
        public ?int $notificationId = null,
    ) {
    }

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, mixed $notification): WebPushMessage
    {
        $title = $this->title ?? (string) config('app.name', 'LeadPro');

        // Unique tag per message — a fixed tag makes the OS replace the previous toast (looks like “only first works”).
        $tag = $this->notificationId !== null
            ? 'lead-'.$this->notificationId.'-'.bin2hex(random_bytes(4))
            : 'lead-'.bin2hex(random_bytes(8));

        return (new WebPushMessage)
            ->title($title)
            ->body($this->body)
            ->tag($tag)
            ->data([
                'url' => url('/dashboard'),
                'notification_id' => $this->notificationId,
            ])
            ->options(['TTL' => 86400]);
    }
}
