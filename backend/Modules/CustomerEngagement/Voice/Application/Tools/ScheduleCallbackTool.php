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
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §12/§14 — the same real
 * ConversationTask model as CreateFollowUpTool, used both as a direct customer-requested
 * callback AND as the human-transfer after-hours/no-agent-available fallback (see
 * HumanTransferService) — one mechanism, two callers, never two engines.
 */
final class ScheduleCallbackTool implements AIToolInterface
{
    public function name(): string
    {
        return 'schedule_callback';
    }

    public function description(): string
    {
        return 'Schedules a callback for the customer at a specific requested time.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'conversation_id' => ['type' => 'string'],
                'due_at' => ['type' => 'string', 'description' => 'ISO-8601 timestamp the customer asked to be called back.'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['conversation_id', 'due_at'],
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
        $dueAt = is_string($input['due_at'] ?? null) ? $input['due_at'] : null;

        if ($conversationId === null || $dueAt === null) {
            return AIToolResult::invalidInput('conversation_id and due_at are required.');
        }

        $conversation = Conversation::query()
            ->where('company_id', $context->companyId)
            ->find($conversationId);

        if ($conversation === null) {
            return AIToolResult::notFound('No such conversation in your company.');
        }

        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : null;

        $task = ConversationTask::create([
            'conversation_id' => $conversation->id,
            'title' => 'Callback requested',
            'description' => $reason !== '' ? $reason : null,
            'due_at' => $dueAt,
            'created_by' => $user->id,
        ]);

        return AIToolResult::success([
            'task_id' => $task->id,
            'due_at' => $task->due_at?->toIso8601String(),
        ]);
    }
}
