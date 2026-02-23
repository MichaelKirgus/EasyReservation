<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailGroupAccount extends Model
{
    protected $table = 'mail_group_accounts';

    protected $fillable = [
        'group_id',
        'account_id',
        'priority',
    ];

    /**
     * Get the group that this account belongs to.
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MailTransportGroup::class);
    }

    /**
     * Get the account that belongs to this group.
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'account_id');
    }
}
