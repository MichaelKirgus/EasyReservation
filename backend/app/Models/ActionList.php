<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ActionList extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the actions associated with this action list.
     */
    public function actions(): HasMany
    {
        return $this->hasMany(ActionListAction::class)->orderBy('sort_order');
    }

    /**
     * Scope to only active action lists.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
