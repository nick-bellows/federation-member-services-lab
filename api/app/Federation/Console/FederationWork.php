<?php

namespace App\Federation\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One process for development and CI: relay the outbox, drain the queue,
 * repeat. Production runs the relay and queue workers as separate processes
 * (ADR-0010); this loop exists so the Compose stack and the e2e job need one
 * command, not two.
 */
final class FederationWork extends Command
{
    protected $signature = 'federation:work
                            {--once : One relay pass and one queue drain, then exit}
                            {--sleep=1 : Seconds to wait between idle passes}';

    protected $description = 'Relay the outbox and drain the queue until stopped';

    public function handle(): int
    {
        do {
            try {
                if (Artisan::call('federation:outbox-relay', ['--once' => true], $this->output) !== self::SUCCESS) {
                    // A refused relay (for example the sync queue driver) is a configuration error, not a retry.
                    return self::FAILURE;
                }

                Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 4, '--sleep' => 0], $this->output);
            } catch (Throwable $e) {
                // A daemon survives one bad pass: the queue keeps its jobs, the outbox keeps its rows.
                $this->error(sprintf('[%s] %s: %s', now()->toIso8601String(), $e::class, $e->getMessage()));
                Log::warning('federation:work pass failed', ['exception' => $e::class, 'message' => $e->getMessage()]);
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            sleep(max(0, (int) $this->option('sleep')));
        } while (true);
    }
}
