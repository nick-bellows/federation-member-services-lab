<?php

namespace App\Federation\Http\Controllers\Concerns;

use App\Federation\Enums\DocumentType;
use App\Federation\Exceptions\ApplicationIncompleteException;
use App\Federation\Exceptions\ApplicationNotEditableException;
use App\Federation\Exceptions\DocumentNotAllowedException;
use App\Federation\Exceptions\DuplicateApplicationException;
use App\Federation\Exceptions\FederationDomainException;
use App\Federation\Exceptions\FieldNotAllowedException;
use App\Federation\Exceptions\IllegalTransitionException;
use App\Federation\Exceptions\InvalidPatchException;
use App\Federation\Exceptions\PatchTestFailedException;
use App\Federation\Exceptions\ReasonRequiredException;
use App\Federation\Exceptions\RoleNotOfferedException;
use App\Federation\Exceptions\TransitionNotAllowedForActorException;
use App\Federation\Exceptions\WindowClosedException;
use LaravelJsonApi\Core\Document\Error;
use LaravelJsonApi\Core\Exceptions\JsonApiException;

/**
 * Domain rules speak in exceptions; HTTP speaks in status codes. The mapping
 * is one place, the messages are the domain's own (never an internal trace),
 * and every error carries a stable code clients can branch on.
 */
trait RendersDomainExceptions
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function domain(callable $callback)
    {
        try {
            return $callback();
        } catch (FederationDomainException $exception) {
            throw $this->toJsonApiException($exception);
        }
    }

    protected function toJsonApiException(FederationDomainException $exception): JsonApiException
    {
        [$status, $code] = match (true) {
            $exception instanceof TransitionNotAllowedForActorException => [403, 'transition_not_allowed_for_actor'],
            $exception instanceof IllegalTransitionException => [409, 'illegal_transition'],
            $exception instanceof DuplicateApplicationException => [409, 'duplicate_application'],
            $exception instanceof WindowClosedException => [409, 'window_closed'],
            $exception instanceof RoleNotOfferedException => [409, 'role_not_offered'],
            $exception instanceof ApplicationNotEditableException => [409, 'application_not_editable'],
            $exception instanceof ReasonRequiredException => [422, 'reason_required'],
            $exception instanceof ApplicationIncompleteException => [422, 'application_incomplete'],
            $exception instanceof DocumentNotAllowedException => [422, 'document_not_allowed'],
            // JSON Patch (ADR-0014): who may not touch what is 403 naming the
            // path; a stale "test" is 409; a document the parser refuses is 422.
            $exception instanceof FieldNotAllowedException => [403, 'field_not_allowed'],
            $exception instanceof PatchTestFailedException => [409, 'patch_test_failed'],
            $exception instanceof InvalidPatchException => [422, 'invalid_patch'],
            default => [422, 'domain_rule_violated'],
        };

        $error = [
            'status' => (string) $status,
            'code' => $code,
            'title' => 'Federation rule',
            'detail' => $exception->getMessage(),
        ];

        if ($exception instanceof FieldNotAllowedException) {
            $error['meta'] = ['path' => $exception->path, 'op' => $exception->operation];
        }

        if ($exception instanceof PatchTestFailedException) {
            $error['meta'] = ['path' => $exception->path];
        }

        if ($exception instanceof ApplicationIncompleteException) {
            $error['meta'] = [
                'missingDocuments' => array_map(static fn (DocumentType $type) => $type->value, $exception->missingDocuments),
                'missingDateOfBirth' => $exception->missingDateOfBirth,
            ];
        }

        return new JsonApiException(Error::fromArray($error));
    }
}
