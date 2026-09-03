<?php

namespace App\Federation\JsonApi\Federations;

use App\Federation\Models\Federation;
use App\JsonApi\V1\PagePagination;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\DateTime;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Relations\HasMany;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn;
use LaravelJsonApi\Eloquent\Schema;

class FederationSchema extends Schema
{
    public static string $model = Federation::class;

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('name')->sortable()->readOnly(),
            Str::make('code')->readOnly(),
            DateTime::make('createdAt')->readOnly(),
            HasMany::make('seasons')->type('seasons')->readOnly(),
            HasMany::make('memberOrganizations')->type('member-organizations')->readOnly(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make($this),
        ];
    }

    public function pagination(): ?Paginator
    {
        return PagePagination::make();
    }

    public function includePaths(): array
    {
        return ['seasons', 'memberOrganizations'];
    }
}
