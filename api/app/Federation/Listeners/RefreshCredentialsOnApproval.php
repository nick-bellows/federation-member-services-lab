<?php

namespace App\Federation\Listeners;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\Events\ApplicationTransitioned;
use App\Federation\LearningCenter\CredentialSnapshots;
use App\Federation\LearningCenter\Exceptions\LearningCenterException;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * After an approval commits, ask the Learning Center once so the reviewer sees
 * participation immediately. Best effort: a slow or absent provider is a log
 * line and an "unknown" until reconciliation, never a failed approval.
 */
final class RefreshCredentialsOnApproval
{
    public function __construct(private readonly CredentialSnapshots $snapshots) {}

    public function handle(ApplicationTransitioned $event): void
    {
        if ($event->to !== ApplicationStatus::APPROVED) {
            return;
        }

        $applicant = $event->application->applicant;
        if (! $applicant instanceof User) {
            return;
        }

        try {
            $this->snapshots->refresh($applicant, User::query()->find($event->actorUserId), $event->requestId);
        } catch (LearningCenterException $e) {
            Log::info('Credential snapshot not refreshed after approval', [
                'application_id' => $event->application->getKey(),
                'request_id' => $event->requestId,
                'reason' => $e::class,
            ]);
        }
    }
}
