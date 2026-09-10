<?php

namespace App\Federation\Http\Controllers;

use App\Federation\Exceptions\SeasonNotInFederationException;
use App\Federation\Http\Controllers\Concerns\RendersDomainExceptions;
use App\Federation\Models\MemberOrganization;
use App\Federation\Models\RegistrationWindow;
use App\Federation\Models\Season;
use App\Federation\Support\AuditRecorder;
use App\Http\Controllers\Controller;
use LaravelJsonApi\Core\Document\Error;
use LaravelJsonApi\Core\Exceptions\JsonApiException;
use LaravelJsonApi\Core\Responses\DataResponse;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\FetchMany;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\FetchOne;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\Store;
use LaravelJsonApi\Laravel\Http\Controllers\Actions\Update;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;

class RegistrationWindowController extends Controller
{
    use FetchMany;
    use FetchOne;
    use RendersDomainExceptions;
    use Store;
    use Update;

    /**
     * The policy allows "create" for any administrator; whether they
     * administer *this* organization, and whether the season belongs to the
     * organization's federation, is decided here, with the payload.
     */
    public function creating(ResourceRequest $request): DataResponse
    {
        $audit = app(AuditRecorder::class);
        $data = $request->validated();
        $organization = MemberOrganization::query()->findOrFail($data['memberOrganization']['id']);

        if (! $request->user()->administersMemberOrganization($organization)) {
            throw new JsonApiException(Error::fromArray([
                'status' => '403',
                'code' => 'organization_not_administered',
                'title' => 'Forbidden',
                'detail' => 'You do not administer this organization.',
            ]));
        }

        $season = Season::query()->findOrFail($data['season']['id']);

        // The hierarchy holds at creation (ADR-0016): a window's season must
        // belong to the organization's federation, whoever opens it.
        if ((int) $season->federation_id !== (int) $organization->federation_id) {
            throw $this->toJsonApiException(new SeasonNotInFederationException);
        }

        $window = RegistrationWindow::query()->create([
            'member_organization_id' => $organization->getKey(),
            'season_id' => $season->getKey(),
            'opens_at' => $data['opensAt'],
            'closes_at' => $data['closesAt'],
            'roles' => $data['roles'],
            'created_by_user_id' => $request->user()->getKey(),
        ]);

        $audit->record(
            actor: $request->user(),
            action: 'window.opened',
            auditable: $window,
            new: ['opens_at' => $window->opens_at->toIso8601String(), 'closes_at' => $window->closes_at->toIso8601String(), 'roles' => $window->roles],
            requestId: $request->attributes->get('federation.request_id'),
        );

        return DataResponse::make($window);
    }
}
