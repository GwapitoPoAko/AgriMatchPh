<?php

namespace App\Policies;

use App\Models\QualityInspection;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class QualityInspectionPolicy
{
    public function before(User $user): ?bool
    {
        if (! $user->isActive()) {
            return false;
        }

        return $user->isAdmin() ? true : null;
    }

    public function view(User $user, QualityInspection $inspection): bool
    {
        return $user->hasRole(User::ROLE_INSPECTOR)
            || $user->id === $inspection->requested_by_id
            || $user->id === $inspection->listing?->seller_id
            || ($inspection->cooperative_id && $user->managesCooperative($inspection->cooperative_id));
    }

    /** Recording a grade is reserved for quality technicians — never the seller. */
    public function record(User $user, QualityInspection $inspection): Response
    {
        if (! $user->hasRole(User::ROLE_INSPECTOR)) {
            return Response::deny('Only quality inspectors can record inspection results.');
        }
        if ($user->id === $inspection->listing?->seller_id) {
            return Response::deny('Inspectors cannot grade their own produce.');
        }
        if (! $inspection->isOpen()) {
            return Response::deny('This inspection is already closed.');
        }
        if ($inspection->inspector_id && $inspection->inspector_id !== $user->id) {
            return Response::deny('This inspection is assigned to another inspector.');
        }

        return Response::allow();
    }
}
