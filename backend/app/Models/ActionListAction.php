<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionListAction extends Model
{
    use HasFactory;

    protected $fillable = [
        'action_list_id',
        'type',
        'config',
        'sort_order',
    ];

    protected $casts = [
        'config' => 'array',
        'sort_order' => 'integer',
    ];

    /**
     * Get the action list that owns this action.
     */
    public function actionList(): BelongsTo
    {
        return $this->belongsTo(ActionList::class);
    }
}
