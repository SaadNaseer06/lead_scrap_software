<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class Notification extends Model
{
    protected $fillable = [
        'user_id',
        'lead_id',
        'type',
        'message',
        'read',
        'read_at',
    ];

    protected $casts = [
        'read' => 'boolean',
        'read_at' => 'datetime',
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
        return $this->belongsTo(Lead::class)->withTrashed();
    }

    /**
     * Mark notification as read
     */
    public function markAsRead(): void
    {
        $data = ['read' => true];
        if (Schema::hasColumn($this->getTable(), 'read_at')) {
            $data['read_at'] = $this->read_at ?? now();
        }
        $this->forceFill($data)->save();
    }

    public function markAsUnread(): void
    {
        $data = ['read' => false];
        if (Schema::hasColumn($this->getTable(), 'read_at')) {
            $data['read_at'] = null;
        }
        $this->forceFill($data)->save();
    }

    public function isRead(): bool
    {
        return $this->read_at !== null || (bool) $this->read;
    }
}
