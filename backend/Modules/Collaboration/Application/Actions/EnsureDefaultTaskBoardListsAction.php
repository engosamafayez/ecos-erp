<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\TaskBoardList;

/**
 * Lazily seeds a company's four default board lists (brief §2 — "do not
 * require a deployment/code change to create a new list", extended here to
 * mean a company never needs a migration re-run either) the first time its
 * board is loaded and it has none yet. Idempotent: a company that already
 * has at least one list (default or custom) is left untouched.
 */
final class EnsureDefaultTaskBoardListsAction extends BaseAction
{
    /** @var list<string> */
    private const DEFAULT_NAMES = ['To Do', 'In Progress', 'Done', 'Cancelled'];

    /**
     * @param  mixed  ...$arguments  [User $actor]
     * @return Collection<int, TaskBoardList>
     */
    public function execute(mixed ...$arguments): Collection
    {
        $actor = $arguments[0] ?? null;

        if (! $actor instanceof User) {
            throw new InvalidArgumentException('EnsureDefaultTaskBoardListsAction::execute expects (User $actor).');
        }

        $existing = TaskBoardList::query()
            ->where('company_id', $actor->company_id)
            ->orderBy('position')
            ->get();

        if ($existing->isNotEmpty()) {
            return $existing;
        }

        return collect(self::DEFAULT_NAMES)->values()->map(function (string $name, int $position) use ($actor): TaskBoardList {
            return TaskBoardList::query()->create([
                'company_id' => $actor->company_id,
                'name' => $name,
                'position' => $position,
                'created_by_user_id' => $actor->id,
            ]);
        });
    }
}
