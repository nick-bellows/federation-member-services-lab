<?php

namespace App\Federation\Events;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\Models\RegistrationApplication;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Dispatched after the transaction that moved an application has committed.
 * Listeners must not assume the request that caused it is still running.
 */
class ApplicationTransitioned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly RegistrationApplication $application,
        public readonly ApplicationStatus $from,
        public readonly ApplicationStatus $to,
        public readonly ?int $actorUserId,
        public readonly ?string $requestId = null,
    ) {}
}
