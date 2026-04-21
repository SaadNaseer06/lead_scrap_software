<?php

namespace App\Services;

use App\Events\NotificationStateChanged;
use App\Models\Lead;
use App\Models\LeadSheet;
use App\Models\Notification;
use App\Models\User;
use App\Notifications\LeadWebPushNotification;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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

    /**
     * Single sales notification after a scrapper bulk-imports into a sheet (not one notification per imported row).
     */
    public static function notifySalesTeamOfSheetImport(LeadSheet $sheet, int $importedCount, Lead $anchorLead): void
    {
        if ($importedCount <= 0) {
            return;
        }

        $salesUsers = User::whereIn('role', ['front_sale', 'upsale'])->get();
        if ($salesUsers->isEmpty()) {
            return;
        }

        $actorName = auth()->user()?->name ?? 'A scrapper';
        $sheetName = $sheet->name;
        $message = "{$actorName} imported {$importedCount} lead(s) into sheet '{$sheetName}'. Open the sheet to review new rows.";

        self::createForUsers($salesUsers, $anchorLead, 'sheet_import', $message);
    }

    /**
     * Notify the lead creator when they are a scrapper and someone else (sales, admin, etc.) updates the lead.
     */
    public static function notifyScrapperWhenLeadUpdatedByOthers(Lead $lead, string $type, string $message): void
    {
        $actor = auth()->user();
        if (! $actor) {
            return;
        }

        $lead->loadMissing('creator');

        $creatorId = (int) ($lead->created_by ?? 0);
        if ($creatorId === 0 || $creatorId === (int) $actor->id) {
            return;
        }

        $creator = $lead->creator ?? User::find($creatorId);
        if (! $creator || ! $creator->isScrapper()) {
            return;
        }

        self::createForUsers([$creator], $lead, $type, $message);
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

                try {
                    event(NotificationStateChanged::fromNotification($notification, 'created'));
                } catch (\Throwable $e) {
                    Log::warning('Pusher broadcast failed (notification saved): '.$e->getMessage());
                }

                $userId = $user->id;
                $messageCopy = $message;
                $notificationId = $notification->id;
                app()->terminating(static function () use ($userId, $messageCopy, $notificationId): void {
                    try {
                        if (! filled(config('webpush.vapid.public_key')) || ! filled(config('webpush.vapid.private_key'))) {
                            return;
                        }
                        $recipient = User::find($userId);
                        if (! $recipient instanceof User) {
                            return;
                        }
                        $recipient->notify(new LeadWebPushNotification(
                            body: $messageCopy,
                            title: (string) config('app.name', 'LeadPro'),
                            notificationId: $notificationId,
                        ));
                    } catch (\Throwable $e) {
                        Log::warning('Web push deferred send failed: '.$e->getMessage());
                    }
                });

                return $notification;
            });
    }

    public static function broadcastStateForUser(int $userId, ?int $notificationId = null, string $action = 'updated'): void
    {
        try {
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
        } catch (\Throwable $e) {
            Log::warning('Pusher broadcast failed: '.$e->getMessage());
        }
    }
}
