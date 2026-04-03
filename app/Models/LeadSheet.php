<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadSheet extends Model
{
    protected $fillable = [
        'name',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function leadGroups(): HasMany
    {
        return $this->hasMany(LeadGroup::class)->orderBy('sort_order')->orderBy('name');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'lead_sheet_team');
    }
}
