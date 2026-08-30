<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'city',
        'address',
        'url',
        'latitude',
        'longitude',
        'contact_email',
        'active',
        'public_transport',
        'notes',
        'capacity_override',
    ];

    protected $casts = [
        'capacity_override' => 'integer',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'location_id');
    }
}
