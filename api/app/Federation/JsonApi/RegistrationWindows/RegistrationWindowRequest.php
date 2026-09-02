<?php

namespace App\Federation\JsonApi\RegistrationWindows;

use App\Federation\Enums\ApplicationRole;
use Illuminate\Validation\Rule;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

class RegistrationWindowRequest extends ResourceRequest
{
    public function rules(): array
    {
        return [
            'opensAt' => ['required', 'date'],
            'closesAt' => ['required', 'date', 'after:opensAt'],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['string', Rule::in(ApplicationRole::values())],
            'memberOrganization' => [$this->isCreating() ? 'required' : 'prohibited', JsonApiRule::toOne()],
            'season' => [$this->isCreating() ? 'required' : 'prohibited', JsonApiRule::toOne()],
        ];
    }
}
