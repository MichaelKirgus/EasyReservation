<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailAccount extends Model
{
    protected $fillable = [
        'name',
        'host',
        'port',
        'encryption',
        'username',
        'password',
        'auth_method',
        'oauth2_client_id',
        'oauth2_client_secret',
        'oauth2_refresh_token',
        'oauth2_access_token',
        'oauth2_token_expiry',
        'ignore_self_signed',
        'tls_version',
        'timeout',
        'rate_limit_enabled',
        'rate_limit_per_minute',
        'rate_limit_per_hour',
        'from_address',
        'reply_to_address',
        'return_path_address',
        'is_active',
    ];

    protected $casts = [
        'ignore_self_signed' => 'boolean',
        'rate_limit_enabled' => 'boolean',
        'rate_limit_per_minute' => 'integer',
        'rate_limit_per_hour' => 'integer',
        'is_active' => 'boolean',
        'oauth2_token_expiry' => 'datetime',
    ];

    /**
     * Get the transport groups that this account belongs to.
     */
    public function transportGroups(): HasMany
    {
        return $this->hasMany(MailGroupAccount::class, 'account_id');
    }

    /**
     * Scope active accounts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
