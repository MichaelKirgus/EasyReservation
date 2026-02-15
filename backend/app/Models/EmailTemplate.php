<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    protected $fillable = [
        'name',
        'type',
        'subject',
        'body',
        'cc',
        'bcc',
    ];
}
