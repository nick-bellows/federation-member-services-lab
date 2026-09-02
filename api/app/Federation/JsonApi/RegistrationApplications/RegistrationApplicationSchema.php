<?php

namespace App\Federation\JsonApi\RegistrationApplications;

use App\Federation\Enums\DocumentType;
use App\Federation\Models\RegistrationApplication;
use App\JsonApi\V1\PagePagination;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\ArrayList;
use LaravelJsonApi\Eloquent\Fields\DateTime;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsTo;
use LaravelJsonApi\Eloquent\Fields\Relations\HasMany;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Filters\Where;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn;
use LaravelJsonApi\Eloquent\Filters\WhereIn;
use LaravelJsonApi\Eloquent\Schema;

class RegistrationApplicationSchema extends Schema
{
    public static string $model = RegistrationApplication::class;

    protected $defaultSort = '-createdAt';

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('role')->readOnlyOnUpdate(),
            Str::make('status')->readOnly(),
            Str::make('statusReason')->readOnly(),
            Str::make('dateOfBirth')->serializeUsing(static fn ($value) => $value?->toDateString()),
            Str::make('phone'),
            Str::make('applicantNotes'),
            ArrayList::make('missingRequiredDocuments')->readOnly()->extractUsing(
                static fn (RegistrationApplication $application) => array_map(
                    static fn (DocumentType $type) => $type->value,
                    $application->missingRequiredDocuments(),
                ),
            ),
            // The audit trail as the applicant and reviewer see it: who did what,
            // when, with which reason. Never the request id or internal ids.
            ArrayList::make('history')->readOnly()->extractUsing(
                static fn (RegistrationApplication $application) => $application->auditEntries()
                    ->with('actor:id,name')
                    ->get()
                    ->map(static fn ($entry) => [
                        'action' => $entry->action,
                        'occurredAt' => $entry->occurred_at?->toIso8601String(),
                        'actor' => $entry->actor?->name,
                        'from' => $entry->previous_state['status'] ?? null,
                        'to' => $entry->new_state['status'] ?? null,
                        'reason' => $entry->reason,
                        'documentType' => $entry->new_state['document_type'] ?? null,
                    ])
                    ->values()
                    ->all(),
            ),
            DateTime::make('submittedAt')->sortable()->readOnly(),
            DateTime::make('decidedAt')->readOnly(),
            DateTime::make('cancelledAt')->readOnly(),
            DateTime::make('createdAt')->sortable()->readOnly(),
            DateTime::make('updatedAt')->readOnly(),
            BelongsTo::make('registrationWindow')->type('registration-windows')->readOnlyOnUpdate(),
            BelongsTo::make('memberOrganization')->type('member-organizations')->readOnly(),
            BelongsTo::make('season')->type('seasons')->readOnly(),
            BelongsTo::make('applicant')->type('federation-users')->readOnly(),
            HasMany::make('documents')->type('application-documents')->readOnly(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make($this),
            WhereIn::make('status')->delimiter(','),
            Where::make('memberOrganization', 'member_organization_id'),
            Where::make('season', 'season_id'),
        ];
    }

    /**
     * Applicants see their own applications; reviewers see those filed with
     * the organizations they administer, directly or through the federation.
     */
    public function indexQuery(?Request $request, Builder $query): Builder
    {
        // No request means a console caller (the OpenAPI generator): unscoped.
        // Every HTTP request has one, and an unauthenticated one never reaches
        // this point because the routes require the oidc guard.
        if ($request === null) {
            return $query;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $organizationIds = $user->administeredMemberOrganizations()->pluck('member_organizations.id');
        $federationIds = $user->administeredFederations()->pluck('federations.id');

        return $query->where(function (Builder $scope) use ($user, $organizationIds, $federationIds) {
            $scope->where('applicant_user_id', $user->getKey())
                ->orWhereIn('member_organization_id', $organizationIds)
                ->orWhereHas('memberOrganization', fn (Builder $organization) => $organization->whereIn('federation_id', $federationIds));
        });
    }

    public function pagination(): ?Paginator
    {
        return PagePagination::make();
    }

    public function includePaths(): array
    {
        return [
            'documents',
            'registrationWindow',
            'registrationWindow.season',
            'memberOrganization',
            'season',
            'applicant',
        ];
    }
}
