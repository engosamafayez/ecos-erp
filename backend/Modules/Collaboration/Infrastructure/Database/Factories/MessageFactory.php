<?php

declare(strict_types=1);

namespace Modules\Collaboration\Infrastructure\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Collaboration\Domain\Enums\MessageType;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\Message;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'sender_user_id' => User::factory(),
            'type' => MessageType::Text,
            'body' => fake()->sentence(),
            'created_at' => now(),
        ];
    }
}
