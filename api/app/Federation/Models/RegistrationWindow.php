<?php

namespace App\Federation\Models;

use App\Federation\Enums\ApplicationRole;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Federation\RegistrationWindowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The period in which a member organization accepts applications for a
 * season, and for which roles.
 */
class RegistrationWindow extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_organization_id',
        'season_id',
        'opens_at',
        'closes_at',
        'roles',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'roles' => 'array',
        ];
    }

    public function isOpenAt(CarbonInterface $moment): bool
    {
        return $this->opens_at->lte($moment) && $this->closes_at->gt($moment);
    }

    public function offers(ApplicationRole $role): bool
    {
        return in_array($role->value, $this->roles ?? [], true);
    }

    public function memberOrganization(): BelongsTo
    {
        return $this->belongsTo(MemberOrganization::class);
    }

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(RegistrationApplication::class);
    }

    protected static function newFactory(): RegistrationWindowFactory
    {
        return RegistrationWindowFactory::new();
    }
}
