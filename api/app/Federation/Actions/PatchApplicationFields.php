<?php

namespace App\Federation\Actions;

use App\Federation\Enums\ApplicationActor;
use App\Federation\Exceptions\ApplicationNotEditableException;
use App\Federation\Exceptions\FieldNotAllowedException;
use App\Federation\Exceptions\InvalidPatchException;
use App\Federation\Exceptions\PatchTestFailedException;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Support\ApplicationActorResolver;
use App\Federation\Support\AuditRecorder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Field-level authorization for partial updates (ADR-0014): every operation
 * of a JSON Patch is checked against the fields the acting person may touch
 * before any of them is applied. One refused operation refuses the patch.
 * The change commits as one transaction with one audit entry carrying the
 * previous and new value of every field it touched.
 */
final class PatchApplicationFields
{
    /** field => column, for the applicant while the application is editable */
    private const APPLICANT_FIELDS = [
        'dateOfBirth' => 'date_of_birth',
        'phone' => 'phone',
        'applicantNotes' => 'applicant_notes',
    ];

    /** field => column, for a reviewer of the application's organization */
    private const REVIEWER_FIELDS = [
        'reviewerNotes' => 'reviewer_notes',
    ];

    private const RULES = [
        'dateOfBirth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
        'phone' => ['nullable', 'string', 'max:32'],
        'applicantNotes' => ['nullable', 'string', 'max:2000'],
        'reviewerNotes' => ['nullable', 'string', 'max:4000'],
    ];

    public function __construct(
        private readonly ApplicationActorResolver $actors,
        private readonly AuditRecorder $audit,
    ) {}

    /**
     * @param  list<array{op: string, path: string, field: string, value?: mixed}>  $operations
     */
    public function execute(RegistrationApplication $application, array $operations, User $actor, ?string $requestId = null): RegistrationApplication
    {
        [$allowed, $applicantLocked] = $this->allowedFields($application, $actor);

        // Authorise every operation before applying any: a patch is all or nothing.
        foreach ($operations as $operation) {
            if (array_key_exists($operation['field'], $allowed)) {
                continue;
            }

            // The applicant's own field, but the application has left their hands:
            // that is the existing 409, not a permission problem.
            if ($applicantLocked && array_key_exists($operation['field'], self::APPLICANT_FIELDS)) {
                throw new ApplicationNotEditableException;
            }

            throw new FieldNotAllowedException($operation['path'], $operation['op']);
        }

        return DB::transaction(function () use ($application, $operations, $actor, $allowed, $requestId): RegistrationApplication {
            $application = RegistrationApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            $previous = [];
            $new = [];

            foreach ($operations as $operation) {
                $column = $allowed[$operation['field']];
                $current = $this->currentValue($application, $column);

                if ($operation['op'] === 'test') {
                    if ($current !== $this->normalise($operation['value'])) {
                        throw new PatchTestFailedException($operation['path']);
                    }

                    continue;
                }

                $value = $operation['op'] === 'remove' ? null : $this->normalise($operation['value']);
                $this->validate($operation['field'], $value);

                if (! array_key_exists($operation['field'], $previous)) {
                    $previous[$operation['field']] = $current;
                }
                $new[$operation['field']] = $value;
                $application->setAttribute($column, $value);
            }

            if ($new === []) {
                return $application;
            }

            $application->save();

            $this->audit->record(
                actor: $actor,
                action: 'application.fields_patched',
                auditable: $application,
                previous: $previous,
                new: $new,
                requestId: $requestId,
            );

            return $application;
        });
    }

    /**
     * The fields this person may touch on this application right now, and
     * whether they are the applicant of an application that has left their
     * hands (so a refused applicant field is "not editable", not "forbidden").
     *
     * @return array{0: array<string, string>, 1: bool} [field => column, applicantLocked]
     */
    private function allowedFields(RegistrationApplication $application, User $actor): array
    {
        $fields = [];
        $applicantLocked = false;

        if ($this->actors->canActAs($actor, $application, ApplicationActor::APPLICANT)) {
            if ($application->isEditableByApplicant()) {
                $fields += self::APPLICANT_FIELDS;
            } else {
                $applicantLocked = true;
            }
        }

        if ($this->actors->canActAs($actor, $application, ApplicationActor::REVIEWER)) {
            $fields += self::REVIEWER_FIELDS;
        }

        return [$fields, $applicantLocked];
    }

    private function currentValue(RegistrationApplication $application, string $column): mixed
    {
        $value = $application->getAttribute($column);

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
    }

    private function normalise(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    private function validate(string $field, mixed $value): void
    {
        $validator = Validator::make([$field => $value], [$field => self::RULES[$field]]);

        if ($validator->fails()) {
            throw new InvalidPatchException($validator->errors()->first($field));
        }
    }
}
