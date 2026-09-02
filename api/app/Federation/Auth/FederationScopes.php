<?php

namespace App\Federation\Auth;

use App\Models\User;

class FederationScopes
{
    /**
     * @return array<int, string>
     */
    public function for(User $user): array
    {
        $scopes = [
            FederationScope::MEMBER_READ_SELF,
            FederationScope::MEMBER_UPDATE_SELF,
            FederationScope::APPLICATION_CREATE,
        ];

        $administersOrganization = $user->administeredMemberOrganizations()->exists();
        $administersFederation = $user->administeredFederations()->exists();

        if ($administersOrganization || $administersFederation) {
            $scopes[] = FederationScope::APPLICATION_REVIEW;
            $scopes[] = FederationScope::ORGANIZATION_MANAGE;
        }

        return array_map(fn (FederationScope $scope) => $scope->value, $scopes);
    }
}
