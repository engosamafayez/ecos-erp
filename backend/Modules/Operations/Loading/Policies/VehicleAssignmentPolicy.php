<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Policies;

use App\Models\User;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;

final class VehicleAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('loading.session.view');
    }

    public function view(User $user, VehicleAssignment $assignment): bool
    {
        return $user->company_id === $assignment->company_id && $user->hasPermissionTo('loading.session.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('loading.vehicle.assign');
    }

    public function load(User $user, VehicleAssignment $assignment): bool
    {
        return $user->company_id === $assignment->company_id && $user->hasPermissionTo('loading.session.operate');
    }

    public function dispatch(User $user, VehicleAssignment $assignment): bool
    {
        return $user->company_id === $assignment->company_id && $user->hasPermissionTo('loading.session.dispatch');
    }

    public function reconcile(User $user, VehicleAssignment $assignment): bool
    {
        return $user->company_id === $assignment->company_id && $user->hasPermissionTo('loading.session.operate');
    }
}
