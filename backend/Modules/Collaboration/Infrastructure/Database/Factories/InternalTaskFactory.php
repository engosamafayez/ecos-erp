<?php

declare(strict_types=1);

namespace Modules\Collaboration\Infrastructure\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Collaboration\Domain\Enums\TaskPriority;
use Modules\Collaboration\Domain\Enums\TaskStatus;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Organization\Companies\Domain\Models\Company;

/** @extends Factory<InternalTask> */
class InternalTaskFactory extends Factory
{
    protected $model = InternalTask::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'creator_user_id' => User::factory(),
            'assignee_user_id' => User::factory(),
            'priority' => TaskPriority::Normal,
            'status' => TaskStatus::Todo,
            'due_at' => null,
        ];
    }
}
