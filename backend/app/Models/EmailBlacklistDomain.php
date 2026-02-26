<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailBlacklistDomain extends Model
{
    protected $fillable = [
        'domain',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
