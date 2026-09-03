<?php

namespace App\Federation\Console;

use App\Federation\Models\OutboxEvent;
use App\Federation\Outbox\ConsumerRegistry;
use App\Federation\Outbox\ProcessOutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turns facts into jobs: unpublished outbox rows, in insertion order, one
 * job per subscribed consumer. With the database queue the job rows and the
 * published_at update commit together; with a remote broker the same loop
 * is at-least-once and the consumers' ledger absorbs the duplicates (ADR-0010).
 */
final class OutboxRelay extends Command
{
    protected $signature = 'federation:outbox-relay
                            {--once : Run one pass and exit}
                            {--limit=100 : Rows per pass}
                            {--sleep=1 : Seconds between passes when nothing was found}';

    protected $description = 'Dispatch unpublished outbox events to their consumers';

    public function handle(ConsumerRegistry $registry): int
    {
        // On the sync driver a dispatch runs the consumer inside the relay's
        // own transaction: a failing consumer would roll the publication back
        // and nothing would ever be delivered. Refuse rather than pretend.
        if (config('queue.default') === 'sync') {
            $this->error('The outbox relay needs a real queue connection; QUEUE_CONNECTION=sync would run consumers inside the relay transaction.');

            return self::FAILURE;
        }

        do {
            $published = $this->pass($registry, (int) $this->option('limit'));

            if ($published > 0) {
                $this->line("published {$published}");
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            if ($published === 0) {
                sleep(max(0, (int) $this->option('sleep')));
            }
        } while (true);
    }

    private function pass(ConsumerRegistry $registry, int $limit): int
    {
        return DB::transaction(function () use ($registry, $limit): int {
            $rows = OutboxEvent::query()->unpublished()->limit($limit)->lockForUpdate()->get();

            foreach ($rows as $row) {
                foreach ($registry->consumersFor($row->event_type) as $consumer) {
                    ProcessOutboxEvent::dispatch($row->event_id, $consumer);
                }

                $row->forceFill(['published_at' => now()])->save();
            }

            return $rows->count();
        });
    }
}
