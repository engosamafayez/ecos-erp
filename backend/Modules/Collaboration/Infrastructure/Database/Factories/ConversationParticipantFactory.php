<?php

declare(strict_types=1);

namespace Modules\Collaboration\Infrastructure\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Collaboration\Domain\Enums\ParticipantRole;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;

/** @extends Factory<ConversationParticipant> */
class ConversationParticipantFactory extends Factory
{
    protected $model = ConversationParticipant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'user_id' => User::factory(),
            'role' => ParticipantRole::Member,
            'joined_at' => now(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (array $attributes): array => ['role' => ParticipantRole::Owner]);
    }

    public function left(): static
    {
        return $this->state(fn (array $attributes): array => ['left_at' => now()]);
    }
}
