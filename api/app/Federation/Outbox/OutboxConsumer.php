<?php

namespace App\Federation\Outbox;

use App\Federation\Models\OutboxEvent;

/**
 * A consumer handles one event inside the transaction the processing job
 * opens; its database writes commit together with the processed-events row.
 * Throwing means "retry later"; returning means "done, never again".
 */
interface OutboxConsumer
{
    public function handle(OutboxEvent $event): void;
}
