<?php

namespace App\Federation\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Federations, seasons and member organizations are reference data: any
 * signed-in federation user may read them, nobody writes them through the API.
 */
class ReadOnlyPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Model $model): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Model $model): bool
    {
        return false;
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }
}
