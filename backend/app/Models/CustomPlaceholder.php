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
        'created_by',
    ];
}
