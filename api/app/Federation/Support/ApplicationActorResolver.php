<?php

namespace App\Federation\Support;

use App\Federation\Enums\ApplicationActor;
use App\Federation\Models\RegistrationApplication;
use App\Models\User;

/**
 * Answers "may this user act as <actor> on this application?".
 *
 * Applicant: the user who filed it. Reviewer: an administrator of the
 * application's organization, or of that organization's federation. Upstream's
 * super admin is deliberately not a reviewer: operating the platform is not
 * the same as deciding registrations.
 */
class ApplicationActorResolver
{
    public function canActAs(User $user, RegistrationApplication $application, ApplicationActor $actor): bool
    {
        return match ($actor) {
            ApplicationActor::APPLICANT => (int) $user->getKey() === (int) $application->applicant_user_id,
            ApplicationActor::REVIEWER => $user->administersMemberOrganization($application->memberOrganization),
        };
    }
}
