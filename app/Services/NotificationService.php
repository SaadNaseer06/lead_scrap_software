<?php

namespace App\Services;

use App\Events\NotificationStateChanged;
use App\Models\Lead;
use App\Models\Notification;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class NotificationService
{
    public static function createForUsers(iterable $users, Lead $lead, string $type, string $message): Collection
    {
        return collect($users)
            ->map(function ($user) use ($lead, $type, $message) {
                $data = [
                    'user_id' => $user->id,
                    'lead_id' => $lead->id,
                    'type' => $type,
                    'message' => $message,
                    'read' => false,
                ];

                if (Schema::hasColumn('notifications', 'read_at')) {
                    $data['read_at'] = null;
                }

                $notification = Notification::create($data);

                event(NotificationStateChanged::fromNotification($notification, 'created'));

                return $notification;
            });
    }

    public static function broadcastStateForUser(int $userId, ?int $notificationId = null, string $action = 'updated'): void
    {
        event(new NotificationStateChanged(
            userId: $userId,
            notificationId: $notificationId,
            action: $action,
            unreadCount: Notification::query()
                ->where('user_id', $userId)
                ->where(function ($builder) {
                    $builder->where('read', false)
                        ->orWhereNull('read');
                })
                ->count(),
        ));
    }
}
