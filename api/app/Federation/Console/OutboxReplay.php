<?php

namespace App\Federation\Console;

use App\Federation\Models\OutboxEvent;
use App\Federation\Outbox\ConsumerRegistry;
use App\Federation\Outbox\ProcessOutboxEvent;
use Illuminate\Console\Command;

/**
 * Re-deliver one failed event (or every failed event) to its consumers. Safe
 * to run twice: consumers that already acted find their ledger row.
 */
final class OutboxReplay extends Command
{
    protected $signature = 'federation:outbox-replay
                            {event? : The event id to replay}
                            {--all : Replay every failed event}';

    protected $description = 'Re-dispatch failed outbox events to their consumers';

    public function handle(ConsumerRegistry $registry): int
    {
        $query = OutboxEvent::query()->failed();

        if ($this->argument('event') !== null) {
            $query = OutboxEvent::query()->where('event_id', $this->argument('event'));
        } elseif (! $this->option('all')) {
            $this->error('Give an event id or --all');

            return self::FAILURE;
        }

        $count = 0;
        foreach ($query->get() as $row) {
            foreach ($registry->consumersFor($row->event_type) as $consumer) {
                ProcessOutboxEvent::dispatch($row->event_id, $consumer);
            }
            $row->forceFill(['failed_at' => null, 'last_error' => null, 'published_at' => $row->published_at ?? now()])->save();
            $count++;
        }

        $this->info("replayed {$count}");

        return self::SUCCESS;
    }
}
