<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'name';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'name',
        'value',
        'is_encrypted',
    ];

    protected $casts = [
        'is_encrypted' => 'boolean',
    ];

    public function getValueAttribute($value)
    {
        if ($this->is_encrypted) {
            return Crypt::decryptString($value);
        }

        return $value;
    }

    public function setValueAttribute($value)
    {
        $encryptedFields = config('encrypted-settings.fields', []);

        $this->attributes['value'] = in_array($this->name, $encryptedFields)
            ? Crypt::encryptString($value)
            : $value;

        $this->attributes['is_encrypted'] = in_array($this->name, $encryptedFields);
    }
}
