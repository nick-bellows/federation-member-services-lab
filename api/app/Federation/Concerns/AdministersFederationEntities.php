<?php

namespace App\Federation\Concerns;

use App\Federation\Models\CredentialSnapshot;
use App\Federation\Models\Federation;
use App\Federation\Models\MemberOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Added to App\Models\User. Administrative roles above the club level live in
 * explicit pivot tables rather than in upstream's club-scoped spatie roles.
 */
trait AdministersFederationEntities
{
    public function administeredFederations(): BelongsToMany
    {
        return $this->belongsToMany(Federation::class, 'federation_administrators')->withTimestamps();
    }

    public function administeredMemberOrganizations(): BelongsToMany
    {
        return $this->belongsToMany(MemberOrganization::class, 'organization_administrators')->withTimestamps();
    }

    public function credentialSnapshot(): HasOne
    {
        return $this->hasOne(CredentialSnapshot::class);
    }

    public function administersMemberOrganization(MemberOrganization $organization): bool
    {
        return $this->administeredMemberOrganizations()->whereKey($organization->getKey())->exists()
            || $this->administeredFederations()->whereKey($organization->federation_id)->exists();
    }
}
