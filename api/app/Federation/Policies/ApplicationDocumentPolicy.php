<?php

namespace App\Federation\Policies;

use App\Federation\Enums\ApplicationActor;
use App\Federation\Models\ApplicationDocument;
use App\Federation\Support\ApplicationActorResolver;
use App\Models\User;

/**
 * A document is visible to whoever may view its application. Attaching is the
 * applicant's job (checked against the target application in the
 * controller); reviewing is the reviewer's, through ReviewDocument.
 */
class ApplicationDocumentPolicy
{
    public function __construct(private readonly ApplicationActorResolver $actors) {}

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ApplicationDocument $document): bool
    {
        return $this->actors->canActAs($user, $document->application, ApplicationActor::APPLICANT)
            || $this->actors->canActAs($user, $document->application, ApplicationActor::REVIEWER);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ApplicationDocument $document): bool
    {
        return $this->actors->canActAs($user, $document->application, ApplicationActor::REVIEWER);
    }

    public function delete(User $user, ApplicationDocument $document): bool
    {
        return false;
    }
}
