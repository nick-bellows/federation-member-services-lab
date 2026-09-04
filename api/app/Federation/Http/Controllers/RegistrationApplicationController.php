<?php

namespace App\Federation\Http\Controllers;

use App\Federation\Actions\PatchApplicationFields;
use App\Federation\Actions\StartApplication;
use App\Federation\Actions\TransitionApplication;
use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Http\Controllers\Concerns\RendersDomainExceptions;
use App\Federation\Http\Middleware\AssignRequestId;
use App\Federation\LearningCenter\CredentialSnapshots;
use App\Federation\LearningCenter\Exceptions\LearningCenterException;
use App\Federation\LearningCenter\Exceptions\LearningCenterUnavailableException;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;
use App\Federation\Support\AuditRecorder;
use App\Federation\Support\JsonPatch;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use LaravelJsonApi\Core\Document\Error;
use LaravelJsonApi\Core\Exceptions\JsonApiException;
use LaravelJsonApi\Core\Responses\DataResponse;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\FetchMany;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\FetchOne;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\Store;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\Update;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;

/**
 * Applications over HTTP. Creating goes through StartApplication; every
 * status change goes through TransitionApplication via the named actions
 * below. The controller never assigns a status itself.
 */
class RegistrationApplicationController extends Controller
{
    use FetchMany;
    use FetchOne;
    use RendersDomainExceptions;
    use Store;
    use Update;

    public const IDEMPOTENCY_HEADER = 'Idempotency-Key';

    public function creating(ResourceRequest $request): DataResponse
    {
        $start = app(StartApplication::class);
        $data = $request->validated();
        $window = RegistrationWindow::query()->findOrFail($data['registrationWindow']['id']);

        $application = $this->domain(fn () => $start->execute(
            $request->user(),
            $window,
            ApplicationRole::from($data['role']),
            $this->idempotencyKey($request),
            AssignRequestId::current($request),
        ));

        // Details may be supplied together with the start.
        $details = array_intersect_key($data, array_flip(['dateOfBirth', 'phone', 'applicantNotes']));

        if ($details !== []) {
            $application->fill([
                'date_of_birth' => $details['dateOfBirth'] ?? null,
                'phone' => $details['phone'] ?? null,
                'applicant_notes' => $details['applicantNotes'] ?? null,
            ])->save();
        }

        return DataResponse::make($application);
    }

    public function updated(RegistrationApplication $application, ResourceRequest $request): void
    {
        app(AuditRecorder::class)->record(
            actor: $request->user(),
            action: 'application.details_updated',
            auditable: $application,
            new: array_intersect_key($request->validated(), array_flip(['dateOfBirth', 'phone', 'applicantNotes'])),
            requestId: AssignRequestId::current($request),
        );
    }

    public function submit(Request $request, RegistrationApplication $application, TransitionApplication $transition): DataResponse
    {
        return $this->transition($request, $application, $transition, ApplicationStatus::SUBMITTED);
    }

    public function cancel(Request $request, RegistrationApplication $application, TransitionApplication $transition): DataResponse
    {
        return $this->transition($request, $application, $transition, ApplicationStatus::CANCELLED);
    }

    public function startReview(Request $request, RegistrationApplication $application, TransitionApplication $transition): DataResponse
    {
        return $this->transition($request, $application, $transition, ApplicationStatus::UNDER_REVIEW);
    }

    public function requestInformation(Request $request, RegistrationApplication $application, TransitionApplication $transition): DataResponse
    {
        return $this->transition($request, $application, $transition, ApplicationStatus::NEEDS_INFORMATION);
    }

    public function approve(Request $request, RegistrationApplication $application, TransitionApplication $transition): DataResponse
    {
        return $this->transition($request, $application, $transition, ApplicationStatus::APPROVED);
    }

    public function reject(Request $request, RegistrationApplication $application, TransitionApplication $transition): DataResponse
    {
        return $this->transition($request, $application, $transition, ApplicationStatus::REJECTED);
    }

    /**
     * A reviewer asks the Learning Center for the applicant's current
     * credentials. The only path that calls the provider during a request;
     * an unavailable provider is a 503 with a stable code, never a hang.
     */
    public function refreshCredentials(Request $request, RegistrationApplication $application, CredentialSnapshots $snapshots): DataResponse
    {
        $this->authorize('review', $application);

        try {
            $snapshots->refresh($application->applicant, $request->user(), AssignRequestId::current($request));
        } catch (LearningCenterUnavailableException $exception) {
            throw new JsonApiException(Error::fromArray([
                'status' => '503',
                'code' => 'learning_center_unavailable',
                'title' => 'Learning Center unavailable',
                'detail' => 'The credential service did not answer in time; the last snapshot, if any, is still shown.',
            ]));
        } catch (LearningCenterException $exception) {
            throw new JsonApiException(Error::fromArray([
                'status' => '502',
                'code' => 'learning_center_error',
                'title' => 'Learning Center error',
                'detail' => 'The credential service answered in a way this application cannot use.',
            ]));
        }

        return DataResponse::make($application->fresh(['applicant.credentialSnapshot']));
    }

    /**
     * JSON Patch (RFC 6902) on the application's fields, authorised operation
     * by operation for the acting person (ADR-0014). The media type is the
     * contract: anything else is 415, a malformed document 422, a field the
     * person may not touch 403 naming the path, a failed test 409.
     */
    public function fields(Request $request, RegistrationApplication $application, PatchApplicationFields $patch): DataResponse
    {
        if (! str_starts_with(strtolower((string) $request->header('Content-Type')), JsonPatch::MEDIA_TYPE)) {
            throw new JsonApiException(Error::fromArray([
                'status' => '415',
                'code' => 'unsupported_media_type',
                'title' => 'Unsupported media type',
                'detail' => 'Send the operations as '.JsonPatch::MEDIA_TYPE.'.',
            ]));
        }

        $this->authorize('view', $application);

        $application = $this->domain(fn () => $patch->execute(
            $application,
            JsonPatch::parse(json_decode($request->getContent(), true)),
            $request->user(),
            AssignRequestId::current($request),
        ));

        return DataResponse::make($application);
    }

    private function transition(Request $request, RegistrationApplication $application, TransitionApplication $transition, ApplicationStatus $to): DataResponse
    {
        $reason = $request->input('meta.reason') ?? $request->input('reason');

        $application = $this->domain(fn () => $transition->execute(
            $application,
            $to,
            $request->user(),
            is_string($reason) && $reason !== '' ? $reason : null,
            AssignRequestId::current($request),
            $this->idempotencyKey($request),
        ));

        return DataResponse::make($application);
    }

    private function idempotencyKey(Request $request): ?string
    {
        $key = (string) $request->header(self::IDEMPOTENCY_HEADER, '');

        return preg_match('/^[A-Za-z0-9._-]{8,64}$/', $key) === 1 ? $key : null;
    }
}
