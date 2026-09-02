<?php

namespace App\Federation\JsonApi\RegistrationWindows;

use App\Federation\Models\RegistrationWindow;
use App\JsonApi\V1\PagePagination;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\ArrayList;
use LaravelJsonApi\Eloquent\Fields\DateTime;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsTo;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Filters\Where;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn;
use LaravelJsonApi\Eloquent\Schema;

class RegistrationWindowSchema extends Schema
{
    public static string $model = RegistrationWindow::class;

    protected $defaultSort = '-opensAt';

    public function fields(): array
    {
        return [
            ID::make(),
            DateTime::make('opensAt')->sortable(),
            DateTime::make('closesAt')->sortable(),
            ArrayList::make('roles'),
            Str::make('isOpen')->readOnly()->extractUsing(
                static fn (RegistrationWindow $window) => $window->isOpenAt(now()) ? 'true' : 'false',
            ),
            DateTime::make('createdAt')->readOnly(),
            DateTime::make('updatedAt')->readOnly(),
            BelongsTo::make('memberOrganization')->type('member-organizations'),
            BelongsTo::make('season')->type('seasons'),
            BelongsTo::make('createdBy')->type('federation-users')->readOnly(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make($this),
            Where::make('memberOrganization', 'member_organization_id'),
            Where::make('season', 'season_id'),
            OpenWindowsFilter::make('open'),
        ];
    }

    public function pagination(): ?Paginator
    {
        return PagePagination::make();
    }

    public function includePaths(): array
    {
        return ['memberOrganization', 'season', 'memberOrganization.federation'];
    }
}
