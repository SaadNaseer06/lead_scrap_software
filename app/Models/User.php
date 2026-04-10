<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use App\Models\Lead;
use App\Models\Notification;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Check if user is admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Check if user is sales (upsale only)
     */
    public function isSales(): bool
    {
        return $this->role === 'upsale';
    }

    /**
     * Check if user is front sale
     */
    public function isFrontSale(): bool
    {
        return $this->role === 'front_sale';
    }

    /**
     * Check if user is upsale
     */
    public function isUpsale(): bool
    {
        return $this->role === 'upsale';
    }

    /**
     * Check if user is part of sales team (upsale, front sale)
     */
    public function isSalesTeam(): bool
    {
        return $this->isUpsale() || $this->isFrontSale();
    }

    /**
     * Check if user is scrapper
     */
    public function isScrapper(): bool
    {
        return $this->role === 'scrapper';
    }

    /**
     * Check if user can create lead sheets
     */
    public function canCreateSheets(): bool
    {
        return $this->isScrapper();
    }

    /**
     * Check if user can create leads
     */
    public function canCreateLeads(): bool
    {
        return $this->isScrapper();
    }

    /**
     * Get leads created by this user
     */
    public function createdLeads()
    {
        return $this->hasMany(Lead::class, 'created_by');
    }

    /**
     * Get leads opened by this user
     */
    public function openedLeads()
    {
        return $this->hasMany(Lead::class, 'opened_by');
    }

    /**
     * Get notifications for this user
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Get lead comments created by this user
     */
    public function leadComments()
    {
        return $this->hasMany(LeadComment::class);
    }

    /**
     * Get teams this user belongs to
     */
    public function teams()
    {
        return $this->belongsToMany(\App\Models\Team::class, 'team_user');
    }
}
