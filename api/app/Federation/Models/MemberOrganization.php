<?php

namespace App\Federation\Models;

use App\Models\Club;
use App\Models\User;
use Database\Factories\Federation\MemberOrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A state association, league or similar body that belongs to one federation
 * and groups clubs. Registration applications are filed with an organization.
 */
class MemberOrganization extends Model
{
    use HasFactory;

    protected $fillable = [
        'federation_id',
        'name',
        'code',
    ];

    public function federation(): BelongsTo
    {
        return $this->belongsTo(Federation::class);
    }

    public function clubs(): HasMany
    {
        return $this->hasMany(Club::class);
    }

    public function administrators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_administrators')->withTimestamps();
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RegistrationApplication::class);
    }

    public function registrationWindows(): HasMany
    {
        return $this->hasMany(RegistrationWindow::class);
    }

    protected static function newFactory(): MemberOrganizationFactory
    {
        return MemberOrganizationFactory::new();
    }
}
