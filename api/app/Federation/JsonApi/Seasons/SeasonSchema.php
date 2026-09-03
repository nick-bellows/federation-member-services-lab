<?php

namespace App\Federation\JsonApi\Seasons;

use App\Federation\Models\Season;
use App\JsonApi\V1\PagePagination;
use LaravelJsonApi\Eloquent\Contracts\Paginator;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Relations\BelongsTo;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Filters\WhereIdIn;
use LaravelJsonApi\Eloquent\Schema;

class SeasonSchema extends Schema
{
    public static string $model = Season::class;

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('label')->sortable()->readOnly(),
            Str::make('startsOn')->readOnly()->serializeUsing(static fn ($value) => $value?->toDateString()),
            Str::make('endsOn')->readOnly()->serializeUsing(static fn ($value) => $value?->toDateString()),
            BelongsTo::make('federation')->type('federations')->readOnly(),
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
        return ['federation'];
    }
}
