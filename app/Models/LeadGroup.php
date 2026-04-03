<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadGroup extends Model
{
    protected $fillable = [
        'lead_sheet_id',
        'name',
        'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function leadSheet(): BelongsTo
    {
        return $this->belongsTo(LeadSheet::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }
}
