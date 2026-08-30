<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailAccount extends Model
{
    use HasFactory;

    protected $table = 'mail_transport_accounts';

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
        'retry_count',
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
        'retry_count' => 'integer',
        'is_active' => 'boolean',
        'oauth2_token_expiry' => 'datetime',
    ];

    /**
     * Decrypt password when retrieving from database.
     * If decryption fails (data not encrypted), returns the raw value.
     */
    public function getPasswordAttribute($value)
    {
        if ($value && !empty($value)) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($value);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Data is not encrypted, return raw value
                return $value;
            }
        }

        return $value;
    }

    /**
     * Encrypt password when storing to database.
     */
    public function setPasswordAttribute($value)
    {
        if ($value && !empty($value)) {
            $this->attributes['password'] = \Illuminate\Support\Facades\Crypt::encryptString($value);
        } else {
            $this->attributes['password'] = $value;
        }
    }

    /**
     * Decrypt oauth2_client_secret when retrieving from database.
     * If decryption fails (data not encrypted), returns the raw value.
     */
    public function getOauth2ClientSecretAttribute($value)
    {
        if ($value && !empty($value)) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($value);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Data is not encrypted, return raw value
                return $value;
            }
        }

        return $value;
    }

    /**
     * Encrypt oauth2_client_secret when storing to database.
     */
    public function setOauth2ClientSecretAttribute($value)
    {
        if ($value && !empty($value)) {
            $this->attributes['oauth2_client_secret'] = \Illuminate\Support\Facades\Crypt::encryptString($value);
        } else {
            $this->attributes['oauth2_client_secret'] = $value;
        }
    }

    /**
     * Decrypt oauth2_refresh_token when retrieving from database.
     * If decryption fails (data not encrypted), returns the raw value.
     */
    public function getOauth2RefreshTokenAttribute($value)
    {
        if ($value && !empty($value)) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($value);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Data is not encrypted, return raw value
                return $value;
            }
        }

        return $value;
    }

    /**
     * Encrypt oauth2_refresh_token when storing to database.
     */
    public function setOauth2RefreshTokenAttribute($value)
    {
        if ($value && !empty($value)) {
            $this->attributes['oauth2_refresh_token'] = \Illuminate\Support\Facades\Crypt::encryptString($value);
        } else {
            $this->attributes['oauth2_refresh_token'] = $value;
        }
    }

    /**
     * Decrypt oauth2_access_token when retrieving from database.
     * If decryption fails (data not encrypted), returns the raw value.
     */
    public function getOauth2AccessTokenAttribute($value)
    {
        if ($value && !empty($value)) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($value);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Data is not encrypted, return raw value
                return $value;
            }
        }

        return $value;
    }

    /**
     * Encrypt oauth2_access_token when storing to database.
     */
    public function setOauth2AccessTokenAttribute($value)
    {
        if ($value && !empty($value)) {
            $this->attributes['oauth2_access_token'] = \Illuminate\Support\Facades\Crypt::encryptString($value);
        } else {
            $this->attributes['oauth2_access_token'] = $value;
        }
    }

    /**
     * Get the transport groups that this account belongs to.
     */
    public function transportGroups(): HasMany
    {
        return $this->hasMany(MailGroupAccount::class, 'account_id');
    }

    /**
     * Decrypt username when retrieving from database.
     * If decryption fails (data not encrypted), returns the raw value.
     */
    public function getUsernameAttribute($value)
    {
        if ($value && !empty($value)) {
            try {
                return \Illuminate\Support\Facades\Crypt::decryptString($value);
            } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                // Data is not encrypted, return raw value
                return $value;
            }
        }

        return $value;
    }

    /**
     * Encrypt username when storing to database.
     */
    public function setUsernameAttribute($value)
    {
        if ($value && !empty($value)) {
            $this->attributes['username'] = \Illuminate\Support\Facades\Crypt::encryptString($value);
        } else {
            $this->attributes['username'] = $value;
        }
    }

    /**
     * Scope active accounts.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
