<?php

namespace App\Federation\Outbox;

use App\Federation\Models\OutboxEvent;
use App\Federation\Models\ProcessedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OpenTelemetry\API\Trace\Propagation\TraceContextPropagator;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use Throwable;

/**
 * One job per event and consumer, so consumers fail and retry independently.
 * The consumer's effect and its ledger row commit in one transaction; a
 * duplicate delivery finds the row and does nothing. Attempts and the last
 * error are mirrored onto the outbox row for operators; the final failure
 * parks the row with failed_at. The job's span continues the trace of the
 * request that wrote the fact, and its log lines carry that request's id.
 */
final class ProcessOutboxEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [2, 10, 60];

    public function __construct(
        public readonly string $eventId,
        public readonly string $consumer,
    ) {}

    public function handle(ConsumerRegistry $registry, TracerInterface $tracer): void
    {
        $event = OutboxEvent::query()->where('event_id', $this->eventId)->firstOrFail();
        $consumer = $registry->get($this->consumer);

        $parent = $event->traceparent
            ? TraceContextPropagator::getInstance()->extract(['traceparent' => $event->traceparent])
            : Context::getCurrent();
        $span = $tracer->spanBuilder('outbox.process')
            ->setParent($parent)
            ->setSpanKind(SpanKind::KIND_CONSUMER)
            ->setAttribute('federation.event_type', $event->event_type)
            ->setAttribute('federation.event_id', $event->event_id)
            ->setAttribute('federation.consumer', $this->consumer)
            ->setAttribute('federation.attempt', $this->attempts())
            ->startSpan();
        $scope = $span->activate();
        Log::shareContext(array_filter([
            'request_id' => $event->request_id,
            'event_id' => $event->event_id,
            'consumer' => $this->consumer,
        ]));

        OutboxEvent::query()->whereKey($event->getKey())->increment('attempts');

        try {
            DB::transaction(function () use ($event, $consumer): void {
                $claimed = ProcessedEvent::query()->insertOrIgnore([
                    'consumer' => $this->consumer,
                    'event_id' => $this->eventId,
                    'processed_at' => now(),
                ]);

                if ($claimed === 0) {
                    return;
                }

                $consumer->handle($event);
            });

            OutboxEvent::query()->whereKey($event->getKey())->update(['last_error' => null]);
        } catch (Throwable $e) {
            OutboxEvent::query()->whereKey($event->getKey())->update([
                'last_error' => mb_substr($this->consumer.': '.$e->getMessage(), 0, 1000),
            ]);
            $span->recordException($e)->setStatus(StatusCode::STATUS_ERROR);
            Log::warning('outbox consumer failed', ['exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)]);

            throw $e;
        } finally {
            $scope->detach();
            $span->end();
            Log::flushSharedContext();
        }
    }

    public function failed(?Throwable $e): void
    {
        OutboxEvent::query()->where('event_id', $this->eventId)->update([
            'failed_at' => now(),
            'last_error' => mb_substr($this->consumer.': '.($e?->getMessage() ?? 'failed'), 0, 1000),
        ]);
        Log::error('outbox event parked', ['event_id' => $this->eventId, 'consumer' => $this->consumer, 'exception' => $e?->getMessage() ? $e::class : 'unknown']);
    }
}
