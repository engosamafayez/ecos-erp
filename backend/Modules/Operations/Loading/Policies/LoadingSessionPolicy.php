<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Policies;

use App\Models\User;
use Modules\Operations\Loading\Domain\Models\LoadingSession;

final class LoadingSessionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('loading.session.view');
    }

    public function view(User $user, LoadingSession $session): bool
    {
        return $user->company_id === $session->company_id && $user->hasPermissionTo('loading.session.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('loading.session.create');
    }

    public function cancel(User $user, LoadingSession $session): bool
    {
        return $user->company_id === $session->company_id && $user->hasPermissionTo('loading.session.cancel');
    }

    public function operate(User $user, LoadingSession $session): bool
    {
        return $user->company_id === $session->company_id && $user->hasPermissionTo('loading.session.operate');
    }

    public function dispatch(User $user, LoadingSession $session): bool
    {
        return $user->company_id === $session->company_id && $user->hasPermissionTo('loading.session.dispatch');
    }

    public function allocate(User $user, LoadingSession $session): bool
    {
        return $user->company_id === $session->company_id && $user->hasPermissionTo('loading.allocation.manage');
    }
}
