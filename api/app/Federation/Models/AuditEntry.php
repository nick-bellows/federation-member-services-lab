<?php

namespace App\Federation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * One immutable line of the audit trail. Rows are created by AuditRecorder
 * and can never be updated or deleted through the model.
 */
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'actor_user_id',
        'actor_type',
        'action',
        'auditable_type',
        'auditable_id',
        'previous_state',
        'new_state',
        'reason',
        'request_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'previous_state' => 'array',
            'new_state' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Audit entries are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Audit entries are append-only.');
        });
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
