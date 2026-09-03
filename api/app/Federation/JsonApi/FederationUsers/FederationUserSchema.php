<?php

namespace App\Federation\JsonApi\FederationUsers;

use App\Models\User;
use LaravelJsonApi\Eloquent\Fields\ID;
use LaravelJsonApi\Eloquent\Fields\Str;
use LaravelJsonApi\Eloquent\Schema;

/**
 * The applicant or reviewer as seen through the federation API: name and
 * e-mail only. Not routable on its own; reachable through includes.
 */
class FederationUserSchema extends Schema
{
    public static string $model = User::class;

    public static function type(): string
    {
        return 'federation-users';
    }

    public function fields(): array
    {
        return [
            ID::make(),
            Str::make('name')->readOnly(),
            Str::make('email')->readOnly(),
        ];
    }

    public function filters(): array
    {
        return [];
    }
}
