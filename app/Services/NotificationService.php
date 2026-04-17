<?php

namespace App\Services;

use App\Events\NotificationStateChanged;
use App\Models\Lead;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class NotificationService
{
    /**
     * Sales "new lead" alerts when the lead has a name, non-empty details, and at least one contact channel:
     * a non-empty phone, or a non-empty email that passes basic format validation.
     * If email is non-empty but invalid, phone alone can still satisfy the contact requirement.
     */
    public static function leadHasCoreFieldsForNewLeadNotification(Lead $lead): bool
    {
        $name = trim((string) ($lead->name ?? ''));
        if ($name === '') {
            return false;
        }

        $detail = trim((string) ($lead->detail ?? ''));
        if ($detail === '') {
            return false;
        }

        $email = trim((string) ($lead->email ?? ''));
        $phone = trim((string) ($lead->phone ?? ''));

        $hasPhone = $phone !== '';
        $emailNonEmpty = $email !== '';
        $emailValid = $emailNonEmpty && (bool) filter_var($email, FILTER_VALIDATE_EMAIL);

        if ($emailNonEmpty && !$emailValid && !$hasPhone) {
            return false;
        }

        return $emailValid || $hasPhone;
    }

    /**
     * Notify sales once per lead when name, details, and email-or-phone criteria are met.
     *
     * @return bool True if new notifications were created this call.
     */
    public static function notifySalesNewLeadWhenCoreFieldsComplete(Lead $lead): bool
    {
        $lead->refresh();

        if (!self::leadHasCoreFieldsForNewLeadNotification($lead)) {
            return false;
        }

        if (Notification::query()
            ->where('lead_id', $lead->id)
            ->where('type', 'new_lead')
            ->exists()) {
            return false;
        }

        $salesUsers = User::whereIn('role', ['front_sale', 'upsale'])->get();

        if ($salesUsers->isEmpty()) {
            return false;
        }

        $adder = auth()->user();
        $adderName = $adder?->name ?? 'Someone';

        self::createForUsers(
            $salesUsers,
            $lead,
            'new_lead',
            "New lead '{$lead->name}' has been added by {$adderName}"
        );

        return true;
    }

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
