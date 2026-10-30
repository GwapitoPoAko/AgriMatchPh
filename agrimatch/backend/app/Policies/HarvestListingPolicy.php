<?php

namespace App\Policies;

use App\Models\Contract;
use App\Models\HarvestListing;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class HarvestListingPolicy
{
    /** Admins bypass record checks, but only while their account is active. */
    public function before(User $user): ?bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->isAdmin() ? true : null;
    }

    public function viewAny(?User $user): bool
    {
        return true;
    }

    public function view(?User $user, HarvestListing $listing): bool
    {
        if (! in_array($listing->status, [HarvestListing::STATUS_DRAFT], true)) {
            return true;
        }

        return $user !== null && $user->id === $listing->seller_id;
    }

    public function create(User $user): Response
    {
        return $user->canSell()
            ? Response::allow()
            : Response::deny('Only farmers, cooperative managers and traders can post harvest listings.');
    }

    public function update(User $user, HarvestListing $listing): Response
    {
        if ($user->id !== $listing->seller_id) {
            return Response::deny('You can only edit your own listings.');
        }
        if ($listing->isPooledMember() && $listing->status === HarvestListing::STATUS_POOLED) {
            return Response::deny('This lot is fully committed to a cooperative pool. Ask your coop manager to release it.');
        }

        return Response::allow();
    }

    public function delete(User $user, HarvestListing $listing): Response
    {
        if ($user->id !== $listing->seller_id) {
            return Response::deny('You can only remove your own listings.');
        }
        if ($listing->contracts()->whereIn('status', [Contract::STATUS_DRAFT, ...Contract::LIVE_STATUSES])->exists()) {
            return Response::deny('This listing has open contracts and cannot be removed. Mark it sold out instead.');
        }
        if ($listing->is_pool_parent) {
            return Response::deny('Dissolve the cooperative pool instead of deleting it.');
        }
        if ($listing->isPooledMember()) {
            return Response::deny('This lot is committed to a cooperative pool.');
        }

        return Response::allow();
    }

    public function viewMatches(User $user, HarvestListing $listing): bool
    {
        return $user->id === $listing->seller_id;
    }

    /** Sellers (or the coop manager of the lot's cooperative) can ask for grading. */
    public function requestInspection(User $user, HarvestListing $listing): Response
    {
        $isOwner = $user->id === $listing->seller_id;
        $isCoopManager = $listing->cooperative_id && $user->managesCooperative($listing->cooperative_id);

        if (! $isOwner && ! $isCoopManager) {
            return Response::deny('Only the seller or their cooperative manager can request an inspection.');
        }
        if ($listing->inspections()->open()->exists()) {
            return Response::deny('An inspection is already in progress for this listing.');
        }

        return Response::allow();
    }

    public function dissolvePool(User $user, HarvestListing $listing): Response
    {
        return $listing->is_pool_parent && $listing->cooperative_id && $user->managesCooperative($listing->cooperative_id)
            ? Response::allow()
            : Response::deny('Only the cooperative manager can dissolve this pool.');
    }
}
