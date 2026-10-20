<?php

namespace App\Policies;

use App\Models\LeaveRequest;
use App\Models\User;

class LeaveRequestPolicy
{
    public function view(User $user, LeaveRequest $request): bool
    {
        return $request->user_id === $user->id || $user->isManagerOf($request->user);
    }

    public function cancel(User $user, LeaveRequest $request): bool
    {
        return $request->user_id === $user->id;
    }

    /** Approve or reject: only the employee's own manager, never yourself. */
    public function decide(User $user, LeaveRequest $request): bool
    {
        return $request->user_id !== $user->id && $user->isManagerOf($request->user);
    }
}
