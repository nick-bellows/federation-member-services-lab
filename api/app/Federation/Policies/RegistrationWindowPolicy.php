<?php

namespace App\Federation\Policies;

use App\Federation\Models\RegistrationWindow;
use App\Models\User;

/**
 * Windows are visible to every signed-in federation user (they are how an
 * applicant finds where to apply) and managed by administrators of the
 * organization or its federation. The object-level check for creation lives
 * in the controller, because "create" has no model yet.
 */
class RegistrationWindowPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, RegistrationWindow $window): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->administeredMemberOrganizations()->exists()
            || $user->administeredFederations()->exists();
    }

    public function update(User $user, RegistrationWindow $window): bool
    {
        return $user->administersMemberOrganization($window->memberOrganization);
    }

    public function delete(User $user, RegistrationWindow $window): bool
    {
        return false;
    }
}
