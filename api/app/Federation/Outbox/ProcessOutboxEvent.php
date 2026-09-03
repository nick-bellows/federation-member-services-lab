<?php

namespace App\Federation\Outbox;

use App\Federation\Models\OutboxEvent;
use App\Federation\Models\ProcessedEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One job per event and consumer, so consumers fail and retry independently.
 * The consumer's effect and its ledger row commit in one transaction; a
 * duplicate delivery finds the row and does nothing. Attempts and the last
 * error are mirrored onto the outbox row for operators; the final failure
 * parks the row with failed_at.
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

    public function handle(ConsumerRegistry $registry): void
    {
        $event = OutboxEvent::query()->where('event_id', $this->eventId)->firstOrFail();
        $consumer = $registry->get($this->consumer);

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
        } catch (Throwable $e) {
            OutboxEvent::query()->whereKey($event->getKey())->update([
                'last_error' => mb_substr($this->consumer.': '.$e->getMessage(), 0, 1000),
            ]);

            throw $e;
        }

        OutboxEvent::query()->whereKey($event->getKey())->update(['last_error' => null]);
    }

    public function failed(?Throwable $e): void
    {
        OutboxEvent::query()->where('event_id', $this->eventId)->update([
            'failed_at' => now(),
            'last_error' => mb_substr($this->consumer.': '.($e?->getMessage() ?? 'failed'), 0, 1000),
        ]);
    }
}
