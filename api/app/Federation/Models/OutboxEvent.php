<?php

namespace App\Federation\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A fact recorded in the same transaction as the state change it describes
 * (ADR-0010). published_at is set by the relay when its jobs are dispatched;
 * attempts, last_error and failed_at are written by the processing job so an
 * operator can read the outbox instead of a job payload.
 */
class OutboxEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'request_id',
        'occurred_at',
        'published_at',
        'attempts',
        'last_error',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    public function scopeUnpublished(Builder $query): Builder
    {
        return $query->whereNull('published_at')->whereNull('failed_at')->orderBy('id');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereNotNull('failed_at')->orderBy('id');
    }
}
