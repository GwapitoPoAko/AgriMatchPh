<?php

namespace App\Policies;

use App\Models\FreightPool;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class FreightPoolPolicy
{
    public function before(User $user): ?bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, FreightPool $pool): bool
    {
        return $user->id === $pool->organizer_id
            || $user->id === $pool->driver_id
            || $pool->members()->where('user_id', $user->id)->exists()
            || ($pool->cooperative_id && $user->managesCooperative($pool->cooperative_id));
    }

    public function create(User $user): Response
    {
        return $user->canShip() ? Response::allow() : Response::deny('Only farmers, coops and traders can organise freight pools.');
    }

    public function join(User $user, FreightPool $pool): Response
    {
        if ($pool->is_solo) {
            return Response::deny('This truck was booked as a solo trip and cannot accept other loads.');
        }
        if (! $user->canShip()) {
            return Response::deny('Your account cannot ship produce.');
        }

        return $pool->isOpen() ? Response::allow() : Response::deny('This pool is no longer accepting loads.');
    }

    /** Organizer/coop confirm or cancel; the assigned driver moves it through transit. */
    public function updateStatus(User $user, FreightPool $pool, string $status): Response
    {
        $isOrganizer = $user->id === $pool->organizer_id
            || ($pool->cooperative_id && $user->managesCooperative($pool->cooperative_id));
        $isDriver = $user->hasRole(User::ROLE_DRIVER) && $user->id === $pool->driver_id;

        $allowed = match ($status) {
            FreightPool::STATUS_CONFIRMED, FreightPool::STATUS_CANCELLED => $isOrganizer,
            FreightPool::STATUS_IN_TRANSIT, FreightPool::STATUS_DELIVERED => $isDriver || $isOrganizer,
            default => false,
        };

        return $allowed ? Response::allow() : Response::deny('You cannot change this pool to that status.');
    }
}
