<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Team extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user');
    }

    public function leadSheets(): BelongsToMany
    {
        return $this->belongsToMany(LeadSheet::class, 'lead_sheet_team');
    }
}
