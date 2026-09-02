<?php

declare(strict_types=1);

namespace Modules\Collaboration\Infrastructure\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Collaboration\Domain\Enums\ConversationType;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Organization\Companies\Domain\Models\Company;

/** @extends Factory<Conversation> */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'type' => ConversationType::Group,
            'title' => fake()->words(3, true),
            'created_by_user_id' => User::factory(),
            'last_message_at' => null,
        ];
    }

    public function direct(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => ConversationType::Direct,
            'title' => null,
        ]);
    }
}
