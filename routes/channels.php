<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('notifications.{userId}', function ($user, int $userId) {
    return (int) $user->id === $userId;
});
