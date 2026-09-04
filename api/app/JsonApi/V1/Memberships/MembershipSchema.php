<?php

namespace App\JsonApi\V1\Memberships;

use App\JsonApi\Filters\MembershipQueryFilter;
use App\Models\Membership;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\DateTime;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Number;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsTo;
use LaravelJsonApi\Eloquent\Fields\Relations\HasMany;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn;
use LaravelJsonApi\Eloquent\Filters\WhereIn;
use App\JsonApi\V1\PagePagination;
use LaravelJsonApi\Eloquent\Schema;

class MembershipSchema extends Schema
{
    /**
     * The model the schema corresponds to.
     */
    public static string $model = Membership::class;

    protected $defaultSort = '-startedAt';

    /**
     * Get the resource fields.
     */
    /**
     * The relations every row's fee needs (docs/PERFORMANCE.md, B6).
     *
     * @var array<int, string>
     */
    protected array $with = ['membershipType', 'club'];

    /**
     * A page of memberships loads its member counts and division fees with the
     * page instead of one query per row (upstream ran about four per row).
     */
    public function indexQuery(?\Illuminate\Http\Request $request, \Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->withCount('members')->withMembersDivisionsFee();
    }

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('bankIban'),
            Str::make('bankAccountHolder'),
            Str::make('notes'),
            Str::make('status'),
            Str::make('monthlyFee')->extractUsing(
                static fn($model) => $model->getMonthlyFee()
            )->readOnly(),
            Number::make('voluntaryContribution'),
            DateTime::make('startedAt')->sortable(),
            DateTime::make('endedAt')->sortable(),
            DateTime::make('createdAt')->sortable()->readOnly(),
            DateTime::make('updatedAt')->sortable()->readOnly(),
            BelongsTo::make('club')->type('clubs'),
            BelongsTo::make('membershipType')->type('membership-types'),
            BelongsTo::make('owner')->type('members'),
            BelongsTo::make('paymentPeriod')->type('payment-periods'),
            Number::make('membersCount')->extractUsing(
                // Loaded with the page by indexQuery(); the query is the fallback for a single resource.
                static fn($model) => $model->members_count ?? $model->members()->count()
            )->readOnly(),
            HasMany::make('members')->type('members'),
        ];
    }

    /**
     * Get the resource filters.
     */
    public function filters(): array
    {
        return [
            WhereIdIn::make($this),
            WhereIn::make('status')->delimiter(','),
            MembershipQueryFilter::make('query'),
        ];
    }

    /**
     * Get the resource paginator.
     */
    public function pagination(): ?Paginator
    {
        return PagePagination::make();
    }

    public function includePaths(): array
    {
        return [
            'owner',
            'membershipType',
            'paymentPeriod',
            'members',
            'members.divisions',
            'club',
            'membershipType.divisionMembershipTypes',
            'membershipType.divisionMembershipTypes.division',
        ];
    }
}
