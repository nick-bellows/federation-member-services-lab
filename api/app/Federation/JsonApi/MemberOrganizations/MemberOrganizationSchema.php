<?php

namespace App\Federation\JsonApi\MemberOrganizations;

use App\Federation\Models\MemberOrganization;
use App\JsonApi\V1\PagePagination;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsTo;
use LaravelJsonApi\Eloquent\Fields\Relations\HasMany;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Filters\Where;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn;
use LaravelJsonApi\Eloquent\Schema;

class MemberOrganizationSchema extends Schema
{
    public static string $model = MemberOrganization::class;

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('name')->sortable()->readOnly(),
            Str::make('code')->sortable()->readOnly(),
            BelongsTo::make('federation')->type('federations')->readOnly(),
            HasMany::make('registrationWindows')->type('registration-windows')->readOnly(),
        ];
    }

    public function filters(): array
    {
        return [
            WhereIdIn::make($this),
            Where::make('code'),
        ];
    }

    public function pagination(): ?Paginator
    {
        return PagePagination::make();
    }

    public function includePaths(): array
    {
        return ['federation', 'registrationWindows', 'registrationWindows.season'];
    }
}
