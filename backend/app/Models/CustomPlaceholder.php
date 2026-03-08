<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomPlaceholder extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'description',
        'type',
        'created_by',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    public function getValueAttribute($value)
    {
        if ($this->is_encrypted) {
            return \Illuminate\Support\Facades\Crypt::decryptString($value);
        }

        return $value;
    }

    public function setValueAttribute($value)
    {
        if ($this->type === 'secret') {
            $this->attributes['value'] = \Illuminate\Support\Facades\Crypt::encryptString($value);
            $this->attributes['is_encrypted'] = true;
        } else {
            $this->attributes['value'] = $value;
            $this->attributes['is_encrypted'] = false;
        }
    }
}
