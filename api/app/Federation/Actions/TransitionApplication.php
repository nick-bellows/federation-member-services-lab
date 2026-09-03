<?php

namespace App\Federation\Actions;

use App\Federation\Enums\ApplicationStatus;
use App\Federation\Events\ApplicationTransitioned;
use App\Federation\Exceptions\ApplicationIncompleteException;
use App\Federation\Exceptions\IllegalTransitionException;
use App\Federation\Exceptions\ReasonRequiredException;
use App\Federation\Exceptions\TransitionNotAllowedForActorException;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Outbox\OutboxEventTypes;
use App\Federation\Outbox\OutboxRecorder;
use App\Federation\StateMachine\ApplicationTransitions;
use App\Federation\Support\ApplicationActorResolver;
use App\Federation\Support\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use Throwable;

/**
 * The only way an application changes status.
 *
 * Inside one transaction: lock the row, honour a repeated idempotency key,
 * check the transition is legal, check the actor may perform it, check a
 * reason is present when required, check completeness before submission,
 * write the new status and timestamps, write the audit entry. The domain
 * event is dispatched only after the transaction commits, so a listener can
 * never observe a state that was rolled back.
 */
class TransitionApplication
{
    /** @var array<string, string> status => outbox event type (ADR-0010) */
    private const OUTBOX_EVENTS = [
        'submitted' => OutboxEventTypes::APPLICATION_SUBMITTED,
        'approved' => OutboxEventTypes::APPLICATION_APPROVED,
        'rejected' => OutboxEventTypes::APPLICATION_REJECTED,
    ];

    public function __construct(
        private readonly ApplicationActorResolver $actors,
        private readonly AuditRecorder $audit,
        private readonly OutboxRecorder $outbox,
        private readonly TracerInterface $tracer,
    ) {}

    public function execute(
        RegistrationApplication $application,
        ApplicationStatus $to,
        User $actor,
        ?string $reason = null,
        ?string $requestId = null,
        ?string $idempotencyKey = null,
    ): RegistrationApplication {
        $span = $this->tracer->spanBuilder('application.transition')
            ->setAttribute('federation.application_id', $application->getKey())
            ->setAttribute('federation.to', $to->value)
            ->startSpan();
        $scope = $span->activate();

        try {
            return $this->transition($application, $to, $actor, $reason, $requestId, $idempotencyKey);
        } catch (Throwable $e) {
            $span->recordException($e)->setStatus(StatusCode::STATUS_ERROR);
            throw $e;
        } finally {
            $scope->detach();
            $span->end();
        }
    }

    private function transition(
        RegistrationApplication $application,
        ApplicationStatus $to,
        User $actor,
        ?string $reason,
        ?string $requestId,
        ?string $idempotencyKey,
    ): RegistrationApplication {
        return DB::transaction(function () use ($application, $to, $actor, $reason, $requestId, $idempotencyKey) {
            $application = RegistrationApplication::query()
                ->lockForUpdate()
                ->findOrFail($application->getKey());

            // A retried request: the transition already happened under this key.
            if ($idempotencyKey !== null
                && $application->transition_idempotency_key === $idempotencyKey
                && $application->status === $to) {
                return $application;
            }

            $from = $application->status;

            if (! ApplicationTransitions::isLegal($from, $to)) {
                throw new IllegalTransitionException($from, $to);
            }

            $required = ApplicationTransitions::actorFor($from, $to);

            if (! $this->actors->canActAs($actor, $application, $required)) {
                throw new TransitionNotAllowedForActorException($from, $to, $required);
            }

            if (ApplicationTransitions::requiresReason($from, $to) && blank($reason)) {
                throw new ReasonRequiredException($to);
            }

            if ($to === ApplicationStatus::SUBMITTED) {
                $this->assertComplete($application);
            }

            $application->applyTransition($to, $this->attributesFor($to, $reason, $idempotencyKey));

            $this->audit->record(
                actor: $actor,
                action: 'application.'.$to->value,
                auditable: $application,
                previous: ['status' => $from->value],
                new: ['status' => $to->value],
                reason: $reason,
                requestId: $requestId,
            );

            if (($eventType = self::OUTBOX_EVENTS[$to->value] ?? null) !== null) {
                $this->outbox->record($eventType, $application, [
                    'application_id' => $application->getKey(),
                    'from' => $from->value,
                    'to' => $to->value,
                    'reason' => $reason,
                    'actor_user_id' => $actor->getKey(),
                    'applicant_user_id' => $application->applicant_user_id,
                    'role' => $application->role->value,
                    'member_organization_id' => $application->member_organization_id,
                    'season_id' => $application->season_id,
                ], $requestId);
            }

            DB::afterCommit(function () use ($application, $from, $to, $actor, $requestId): void {
                ApplicationTransitioned::dispatch($application, $from, $to, $actor->getKey(), $requestId);
            });

            return $application;
        });
    }

    private function assertComplete(RegistrationApplication $application): void
    {
        $missingDocuments = $application->missingRequiredDocuments();
        $missingDateOfBirth = $application->date_of_birth === null;

        if ($missingDocuments !== [] || $missingDateOfBirth) {
            throw new ApplicationIncompleteException($missingDocuments, $missingDateOfBirth);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attributesFor(ApplicationStatus $to, ?string $reason, ?string $idempotencyKey): array
    {
        $attributes = [
            'status_reason' => $reason,
            'transition_idempotency_key' => $idempotencyKey,
        ];

        return match ($to) {
            ApplicationStatus::SUBMITTED => $attributes + ['submitted_at' => now()],
            ApplicationStatus::APPROVED,
            ApplicationStatus::REJECTED => $attributes + ['decided_at' => now()],
            ApplicationStatus::CANCELLED => $attributes + ['cancelled_at' => now()],
            default => $attributes,
        };
    }
}
