<?php

namespace App\Federation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What the notification consumer produces: one row per person and event,
 * in place of an email while the stack has no mail service. The unique key
 * on (user, event) is the second line behind the consumer's ledger.
 */
class FederationNotification extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'event_id', 'template', 'payload', 'created_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
