<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\TaskBoardList;

/**
 * Full-rewrite reorder scoped to one company's board lists (brief §25) —
 * always a small, bounded set (a handful of lists), never the whole board's
 * cards, so a dense-integer rewrite here is safe and simple.
 */
final class ReorderTaskBoardListsAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, list<string> $orderedListIds] */
    public function execute(mixed ...$arguments): void
    {
        $actor = $arguments[0] ?? null;
        $orderedListIds = $arguments[1] ?? null;

        if (! $actor instanceof User || ! is_array($orderedListIds)) {
            throw new InvalidArgumentException('ReorderTaskBoardListsAction::execute expects (User $actor, array $orderedListIds).');
        }

        DB::transaction(function () use ($actor, $orderedListIds): void {
            $lists = TaskBoardList::query()
                ->where('company_id', $actor->company_id)
                ->whereIn('id', $orderedListIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($lists->count() !== count($orderedListIds)) {
                throw new AuthorizationException('One or more board lists do not belong to your company.');
            }

            foreach (array_values($orderedListIds) as $index => $listId) {
                $lists->get($listId)?->update(['position' => $index]);
            }
        });
    }
}
