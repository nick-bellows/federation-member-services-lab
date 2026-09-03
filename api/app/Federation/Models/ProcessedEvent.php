<?php

namespace App\Federation\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The per-consumer ledger: one row per (consumer, event) once the consumer's
 * effect has committed. Inserted in the consumer's own transaction, so a
 * retry after a crash finds either both the effect and the row or neither.
 */
class ProcessedEvent extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = ['consumer', 'event_id', 'processed_at'];

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }
}
