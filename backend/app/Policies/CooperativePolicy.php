<?php

namespace App\Policies;

use App\Models\Cooperative;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CooperativePolicy
{
    public function before(User $user): ?bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, Cooperative $coop): bool
    {
        return $user->cooperativeId() === $coop->id;
    }

    public function managePools(User $user, Cooperative $coop): Response
    {
        return $user->hasRole(User::ROLE_COOP_MANAGER) && $coop->manager_id === $user->id
            ? Response::allow()
            : Response::deny('Only this cooperative\'s manager can build pools.');
    }
}
