<?php

namespace App\Federation\Outbox;

use App\Federation\Models\OutboxEvent;
use App\Federation\Observability\Tracing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use OpenTelemetry\API\Trace\Span;

/**
 * Writes a fact into the outbox. Only valid inside the transaction that
 * changes the state the fact describes: that is the whole point (ADR-0010),
 * so calling it outside one is a programming error, not a runtime condition.
 */
final class OutboxRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $eventType, Model $aggregate, array $payload, ?string $requestId = null): OutboxEvent
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException("Outbox event {$eventType} recorded outside a transaction");
        }

        return OutboxEvent::query()->create([
            'event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'aggregate_type' => $aggregate->getMorphClass(),
            'aggregate_id' => $aggregate->getKey(),
            'payload' => $payload,
            'request_id' => $requestId,
            // The trace that wrote the fact; the worker's span continues it (ADR-0012).
            'traceparent' => Tracing::traceparent(Span::getCurrent()),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }
}
