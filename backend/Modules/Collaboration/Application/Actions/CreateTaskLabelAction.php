<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\TaskLabel;

final class CreateTaskLabelAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, string $name, string $color] */
    public function execute(mixed ...$arguments): TaskLabel
    {
        $actor = $arguments[0] ?? null;
        $name = $arguments[1] ?? null;
        $color = $arguments[2] ?? null;

        if (! $actor instanceof User || ! is_string($name) || trim($name) === '' || ! is_string($color)) {
            throw new InvalidArgumentException('CreateTaskLabelAction::execute expects (User $actor, string $name, string $color).');
        }

        return TaskLabel::query()->create([
            'company_id' => $actor->company_id,
            'name' => trim($name),
            'color' => $color,
        ]);
    }
}
