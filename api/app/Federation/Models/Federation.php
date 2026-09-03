<?php

namespace App\Federation\Models;

use App\Models\User;
use Database\Factories\Federation\FederationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The top of the hierarchy: Federation → Member Organization → Club → Member.
 */
class Federation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
    ];

    public function seasons(): HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function memberOrganizations(): HasMany
    {
        return $this->hasMany(MemberOrganization::class);
    }

    public function administrators(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'federation_administrators')->withTimestamps();
    }

    protected static function newFactory(): FederationFactory
    {
        return FederationFactory::new();
    }
}
