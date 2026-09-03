<?php

namespace App\Federation\Policies;

use App\Federation\Enums\ApplicationActor;
use App\Federation\Models\RegistrationApplication;
use App\Federation\Support\ApplicationActorResolver;
use App\Models\User;

/**
 * Applicants see and edit their own applications while editable; reviewers
 * see the applications of the organizations they administer. Status changes
 * are not "update": they go through the transition actions, which carry
 * their own actor rules.
 */
class RegistrationApplicationPolicy
{
    public function __construct(private readonly ApplicationActorResolver $actors) {}

    public function viewAny(User $user): bool
    {
        // The index query is scoped to what the user may see (see the schema).
        return true;
    }

    public function view(User $user, RegistrationApplication $application): bool
    {
        return $this->actors->canActAs($user, $application, ApplicationActor::APPLICANT)
            || $this->actors->canActAs($user, $application, ApplicationActor::REVIEWER);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, RegistrationApplication $application): bool
    {
        return $this->actors->canActAs($user, $application, ApplicationActor::APPLICANT)
            && $application->isEditableByApplicant();
    }

    public function delete(User $user, RegistrationApplication $application): bool
    {
        return false;
    }

    public function review(User $user, RegistrationApplication $application): bool
    {
        return $this->actors->canActAs($user, $application, ApplicationActor::REVIEWER);
    }
}
