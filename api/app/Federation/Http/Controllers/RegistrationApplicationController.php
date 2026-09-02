<?php

namespace App\Federation\Http\Controllers;

use App\Federation\Actions\StartApplication;
use App\Federation\Actions\TransitionApplication;
use App\Federation\Enums\ApplicationRole;
use App\Federation\Enums\ApplicationStatus;
use App\Federation\Http\Controllers\Concerns\RendersDomainExceptions;
use App\Federation\Http\Middleware\AssignRequestId;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Models\RegistrationWindow;
use App\Federation\Support\AuditRecorder;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
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
