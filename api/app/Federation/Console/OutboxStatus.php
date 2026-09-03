<?php

namespace App\Federation\Console;

use App\Federation\Models\OutboxEvent;
use App\Federation\Models\ProcessedEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * What an operator reads first: how much is waiting, how old the oldest
 * wait is, what has failed and why. Exit code 1 when anything has failed,
 * so a scheduler can alert on it.
 */
final class OutboxStatus extends Command
{
    protected $signature = 'federation:outbox-status';

    protected $description = 'Report unpublished, failed and processed outbox events';

    public function handle(): int
    {
        $unpublished = OutboxEvent::query()->unpublished()->count();
        $oldest = OutboxEvent::query()->unpublished()->value('occurred_at');
        $failed = OutboxEvent::query()->failed()->get(['event_id', 'event_type', 'attempts', 'last_error', 'failed_at']);
        $queued = DB::table('jobs')->count();
        $failedJobs = DB::table('failed_jobs')->count();
        $processed = ProcessedEvent::query()->count();

        $this->info(sprintf(
            'unpublished=%d oldest=%s queued_jobs=%d failed_jobs=%d failed_events=%d processed=%d',
            $unpublished,
            $oldest ? now()->diffForHumans($oldest, true).' ago' : '-',
            $queued,
            $failedJobs,
            $failed->count(),
            $processed,
        ));

        foreach ($failed as $row) {
            $this->warn(sprintf('failed %s %s attempts=%d at %s: %s', $row->event_type, $row->event_id, $row->attempts, $row->failed_at, $row->last_error));
        }

        return $failed->isEmpty() && $failedJobs === 0 ? self::SUCCESS : self::FAILURE;
    }
}
