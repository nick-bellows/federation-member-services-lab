<?php

namespace App\Federation\JsonApi\RegistrationApplications;

use App\Federation\Enums\ApplicationRole;
use Illuminate\Validation\Rule;
use LaravelJsonApi\Laravel\Http\Requests\ResourceRequest;
use LaravelJsonApi\Validation\Rule as JsonApiRule;

/**
 * Role and window are fixed once an application exists: the schema marks
 * them read-only on update, so they never reach these rules on PATCH.
 */
class RegistrationApplicationRequest extends ResourceRequest
{
    public function rules(): array
    {
        $rules = [
            'dateOfBirth' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'phone' => ['nullable', 'string', 'max:32'],
            'applicantNotes' => ['nullable', 'string', 'max:2000'],
        ];

        if ($this->isCreating()) {
            $rules['role'] = ['required', Rule::in(ApplicationRole::values())];
            $rules['registrationWindow'] = ['required', JsonApiRule::toOne()];
        }

        return $rules;
    }
}
