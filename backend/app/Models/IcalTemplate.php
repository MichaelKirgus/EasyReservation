<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IcalTemplate extends Model
{
    protected $fillable = [
        'name',
        'content',
    ];

    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class, 'ical_template_id');
    }
}
