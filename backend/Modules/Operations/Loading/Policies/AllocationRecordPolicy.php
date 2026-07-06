<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Policies;

use App\Models\User;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;

final class AllocationRecordPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('loading.allocation.view');
    }

    public function view(User $user, AllocationRecord $record): bool
    {
        return $user->company_id === $record->company_id && $user->hasPermissionTo('loading.allocation.view');
    }

    public function override(User $user, AllocationRecord $record): bool
    {
        return $user->company_id === $record->company_id && $user->hasPermissionTo('loading.allocation.override');
    }
}
