<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Lead extends Model
{
    protected $fillable = [
        'created_by',
        'opened_by',
        'lead_sheet_id',
        'lead_date',
        'name',
        'email',
        'phone',
        'company',
        'services',
        'budget',
        'credits',
        'location',
        'position',
        'platform',
        'linkedin',
        'detail',
        'web_link',
        'notes',
        'status',
        'opened_at',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'lead_date' => 'date',
    ];

    /**
     * Get the user who created this lead
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the user who opened this lead
     */
    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * Get the sheet this lead belongs to
     */
    public function leadSheet(): BelongsTo
    {
        return $this->belongsTo(LeadSheet::class);
    }

    /**
     * Get notifications for this lead
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get comments for this lead
     */
    public function comments()
    {
        return $this->hasMany(LeadComment::class);
    }

    /**
     * Mark lead as opened by a user
     */
    public function markAsOpened(User $user): void
    {
        if ($this->opened_by === null) {
            $this->update([
                'opened_by' => $user->id,
                'opened_at' => now(),
            ]);
        }
    }
}
