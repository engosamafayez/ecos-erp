<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskChecklist;
use Modules\Collaboration\Presentation\Http\Policies\TaskPolicy;

/** Full-rewrite reorder scoped to one checklist's items — always a small, bounded set (brief §13/§25). */
final class ReorderTaskChecklistItemsAction extends BaseAction
{
    public function __construct(private readonly TaskPolicy $policy) {}

    /** @param  mixed  ...$arguments  [User $actor, InternalTask $task, TaskChecklist $checklist, list<string> $orderedItemIds] */
    public function execute(mixed ...$arguments): void
    {
        $actor = $arguments[0] ?? null;
        $task = $arguments[1] ?? null;
        $checklist = $arguments[2] ?? null;
        $orderedItemIds = $arguments[3] ?? null;

        if (! $actor instanceof User || ! $task instanceof InternalTask || ! $checklist instanceof TaskChecklist || ! is_array($orderedItemIds)) {
            throw new InvalidArgumentException('ReorderTaskChecklistItemsAction::execute expects (User $actor, InternalTask $task, TaskChecklist $checklist, array $orderedItemIds).');
        }

        if (! $this->policy->manageChecklist($actor, $task)) {
            throw new AuthorizationException('You do not have access to this task\'s checklist.');
        }

        if ($checklist->task_id !== $task->id) {
            throw new AuthorizationException('That checklist does not belong to this task.');
        }

        DB::transaction(function () use ($checklist, $orderedItemIds): void {
            $items = $checklist->items()->lockForUpdate()->get()->keyBy('id');

            if ($items->count() !== count($orderedItemIds)) {
                throw new AuthorizationException('The submitted items do not match this checklist.');
            }

            foreach (array_values($orderedItemIds) as $index => $itemId) {
                $items->get($itemId)?->update(['position' => $index]);
            }
        });
    }
}
