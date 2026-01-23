<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'lead_id',
        'type',
        'message',
        'read',
    ];

    protected $casts = [
        'read' => 'boolean',
    ];

    /**
     * Get the user who owns this notification
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the lead associated with this notification
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(): void
    {
        $this->update(['read' => true]);
    }
}
