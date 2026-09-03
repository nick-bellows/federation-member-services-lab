<?php

namespace App\Federation\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last credentials answer for one user: the provider's payload verbatim,
 * when the provider evaluated it and when this side fetched it. Reads derive
 * participation from this row; they never call the provider.
 */
class CredentialSnapshot extends Model
{
    use HasFactory;

    public const STATUS_NOT_FOUND = 'not_found';

    protected $fillable = [
        'user_id',
        'subject',
        'contract',
        'eligibility_status',
        'payload',
        'source_as_of',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'source_as_of' => 'immutable_datetime',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isStale(int $ttlMinutes): bool
    {
        return $this->fetched_at->addMinutes($ttlMinutes)->isPast();
    }

    public function hasFacts(): bool
    {
        return $this->eligibility_status !== self::STATUS_NOT_FOUND && is_array($this->payload);
    }
}
