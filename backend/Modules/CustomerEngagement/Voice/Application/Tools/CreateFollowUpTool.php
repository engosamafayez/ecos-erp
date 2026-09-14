<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Tools;

use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\CustomerEngagement\Domain\Models\Conversation;
use Modules\CustomerEngagement\Domain\Models\ConversationTask;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §12 — one of Voice's three
 * approved LOW-RISK CONFIRMED ACTION tools. Creates a plain follow-up against the call's own
 * Conversation using the existing ConversationTask model directly — the same real, existing
 * model a human agent working the inbox already uses, per the architecture report's CALL MODEL
 * section. Not a duplicate task engine.
 */
final class CreateFollowUpTool implements AIToolInterface
{
    public function name(): string
    {
        return 'create_follow_up';
    }

    public function description(): string
    {
        return 'Creates an internal follow-up task on this call for a team member to action later.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'conversation_id' => ['type' => 'string'],
                'title' => ['type' => 'string'],
                'due_at' => ['type' => 'string', 'description' => 'ISO-8601 timestamp, optional.'],
            ],
            'required' => ['conversation_id', 'title'],
        ];
    }

    public function permission(): string
    {
        return 'cep.inbox.manage';
    }

    public function scope(): AIToolScope
    {
        return AIToolScope::Company;
    }

    public function classification(): AIToolClassification
    {
        return AIToolClassification::ConfirmedAction;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function execute(AIRequestContext $context, User $user, array $input): AIToolResult
    {
        $conversationId = is_string($input['conversation_id'] ?? null) ? $input['conversation_id'] : null;
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';

        if ($conversationId === null || $title === '') {
            return AIToolResult::invalidInput('conversation_id and title are required.');
        }

        $conversation = Conversation::query()
            ->where('company_id', $context->companyId)
            ->find($conversationId);

        if ($conversation === null) {
            return AIToolResult::notFound('No such conversation in your company.');
        }

        $dueAt = is_string($input['due_at'] ?? null) ? $input['due_at'] : null;

        $task = ConversationTask::create([
            'conversation_id' => $conversation->id,
            'title' => $title,
            'due_at' => $dueAt,
            'created_by' => $user->id,
        ]);

        return AIToolResult::success([
            'task_id' => $task->id,
            'title' => $task->title,
            'due_at' => $task->due_at?->toIso8601String(),
        ]);
    }
}
