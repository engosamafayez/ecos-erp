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

    /**
     * `mixed`, not `void` (same pre-existing Liskov-violation bug found and
     * fixed across this module while implementing remediation-010 — see
     * ReorderTaskBoardListsAction's docblock for the full explanation; this
     * endpoint has always fatally 500'd — also explains why the checklist
     * item reorder UI, per this task's own research, was never built:
     * the backend it would have called never worked either).
     *
     * @param  mixed  ...$arguments  [User $actor, InternalTask $task, TaskChecklist $checklist, list<string> $orderedItemIds]
     */
    public function execute(mixed ...$arguments): mixed
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

        // A bare `return;` does not satisfy a declared `mixed` return type
        // (confirmed the hard way: PHP rejects it as "none returned") —
        // unlike `void`/undeclared returns, `mixed` requires an actual value.
        return null;
    }
}
