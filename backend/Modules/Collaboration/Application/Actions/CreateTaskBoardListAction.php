<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\TaskBoardList;

/** New lists always append to the end of the (non-archived) board (brief §2). */
final class CreateTaskBoardListAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, string $name] */
    public function execute(mixed ...$arguments): TaskBoardList
    {
        $actor = $arguments[0] ?? null;
        $name = $arguments[1] ?? null;

        if (! $actor instanceof User || ! is_string($name) || trim($name) === '') {
            throw new InvalidArgumentException('CreateTaskBoardListAction::execute expects (User $actor, string $name).');
        }

        return DB::transaction(function () use ($actor, $name): TaskBoardList {
            $nextPosition = 1 + (int) (TaskBoardList::query()
                ->where('company_id', $actor->company_id)
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->max('position') ?? -1);

            return TaskBoardList::query()->create([
                'company_id' => $actor->company_id,
                'name' => trim($name),
                'position' => $nextPosition,
                'created_by_user_id' => $actor->id,
            ]);
        });
    }
}
