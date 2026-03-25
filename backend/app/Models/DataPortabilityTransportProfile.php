<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class DataPortabilityTransportProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'target_base_url',
        'target_api_token',
        'is_active',
        'timeout_seconds',
        'created_by_user_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'timeout_seconds' => 'integer',
    ];

    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function getTargetApiTokenAttribute($value): ?string
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return $value;
        }
    }

    public function setTargetApiTokenAttribute($value): void
    {
        if (! is_string($value) || $value === '') {
            $this->attributes['target_api_token'] = $value;
            return;
        }

        $this->attributes['target_api_token'] = Crypt::encryptString($value);
    }
}
