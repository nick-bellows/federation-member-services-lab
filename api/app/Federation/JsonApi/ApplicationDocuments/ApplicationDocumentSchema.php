<?php

namespace App\Federation\JsonApi\ApplicationDocuments;

use App\Federation\Models\ApplicationDocument;
use App\JsonApi\V1\PagePagination;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\DateTime;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Number;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsTo;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Filters\Where;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn;
use LaravelJsonApi\Eloquent\Schema;

class ApplicationDocumentSchema extends Schema
{
    public static string $model = ApplicationDocument::class;

    public function fields(): array
    {
        return [
            ID::make(),
            // Metadata is written by the applicant at creation and never patched;
            // the review fields are written by a reviewer on update only.
            Str::make('documentType')->readOnlyOnUpdate(),
            Str::make('fileName')->readOnlyOnUpdate(),
            Str::make('mimeType')->readOnlyOnUpdate(),
            Number::make('sizeBytes')->readOnlyOnUpdate(),
            Str::make('checksumSha256')->readOnlyOnUpdate(),
            Str::make('reviewStatus')->readOnlyOnCreate(),
            Str::make('reviewNote')->readOnlyOnCreate(),
            DateTime::make('reviewedAt')->readOnly(),
            DateTime::make('createdAt')->readOnly(),
            DateTime::make('updatedAt')->readOnly(),
            BelongsTo::make('application')->type('registration-applications')->readOnlyOnUpdate(),
            BelongsTo::make('reviewedBy')->type('federation-users')->readOnly(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make($this),
            Where::make('application', 'registration_application_id'),
        ];
    }

    /**
     * Documents of the applications the user may see.
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

        return $query->whereHas('application', function (Builder $application) use ($user, $organizationIds, $federationIds) {
            $application->where('applicant_user_id', $user->getKey())
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
        return ['application', 'reviewedBy'];
    }
}
