<?php

namespace App\Events;

use App\Models\Notification;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationStateChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $userId,
        public ?int $notificationId = null,
        public string $action = 'updated',
        public int $unreadCount = 0,
        public ?string $pushTitle = null,
        public ?string $pushBody = null,
    ) {
    }

    public static function fromNotification(Notification $notification, string $action = 'updated'): self
    {
        $pushTitle = $action === 'created' ? (string) config('app.name', 'LeadPro') : null;
        $pushBody = $action === 'created' ? (string) $notification->message : null;

        return new self(
            userId: $notification->user_id,
            notificationId: $notification->id,
            action: $action,
            unreadCount: Notification::query()
                ->where('user_id', $notification->user_id)
                ->where(function ($builder) {
                    $builder->where('read', false)
                        ->orWhereNull('read');
                })
                ->count(),
            pushTitle: $pushTitle,
            pushBody: $pushBody,
        );
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('notifications.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'notification.state-changed';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'notification_id' => $this->notificationId,
            'action' => $this->action,
            'unread_count' => $this->unreadCount,
            'push_title' => $this->pushTitle,
            'push_body' => $this->pushBody,
        ];
    }
}
