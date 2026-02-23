<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailTransportGroup extends Model
{
    protected $table = 'mail_transport_groups';

    protected $fillable = [
        'name',
        'description',
        'rate_limit_enabled',
        'rate_limit_per_minute',
        'rate_limit_per_hour',
        'failover_strategy',
        'max_retries_per_account',
        'is_active',
    ];

    protected $casts = [
        'rate_limit_enabled' => 'boolean',
        'rate_limit_per_minute' => 'integer',
        'rate_limit_per_hour' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Get the accounts that belong to this group.
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(MailGroupAccount::class, 'group_id');
    }

    /**
     * Scope active groups.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
