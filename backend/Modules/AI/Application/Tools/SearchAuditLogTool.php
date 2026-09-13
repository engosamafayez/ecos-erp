<?php

declare(strict_types=1);

namespace Modules\AI\Application\Tools;

use App\Core\Audit\AuditQueryService;
use App\Models\User;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\Enums\AIToolClassification;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;

/**
 * Wraps the CORE-02 central Audit read authority. Company scope and the
 * system-actor exception are enforced entirely inside AuditQueryService — this
 * tool adds nothing beyond its own permission declaration.
 */
final class SearchAuditLogTool implements AIToolInterface
{
    private const MAX_PER_PAGE = 20;

    public function __construct(private readonly AuditQueryService $audit) {}

    public function name(): string
    {
        return 'search_audit_log';
    }

    public function description(): string
    {
        return 'Searches the central ECOS audit trail (who did what, and when) by actor, action, entity type/id, or date range.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'description' => 'Exact or partial action name, e.g. "user.activated".'],
                'entity_type' => ['type' => 'string', 'description' => 'e.g. "order", "user", "role".'],
                'entity_id' => ['type' => 'string'],
                'date_from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
                'date_to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'],
            ],
        ];
    }

    public function permission(): string
    {
        return 'system.audit.view';
    }

    public function scope(): AIToolScope
    {
        return AIToolScope::Company;
    }

    public function classification(): AIToolClassification
    {
        return AIToolClassification::Read;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public function execute(AIRequestContext $context, User $user, array $input): AIToolResult
    {
        $filters = array_filter([
            'action' => is_string($input['action'] ?? null) ? $input['action'] : null,
            'entity_type' => is_string($input['entity_type'] ?? null) ? $input['entity_type'] : null,
            'entity_id' => is_string($input['entity_id'] ?? null) ? $input['entity_id'] : null,
            'date_from' => is_string($input['date_from'] ?? null) ? $input['date_from'] : null,
            'date_to' => is_string($input['date_to'] ?? null) ? $input['date_to'] : null,
        ]);

        $paginator = $this->audit->search($filters, 1, self::MAX_PER_PAGE);

        $rows = array_map(static fn ($log): array => [
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'occurred_at' => $log->occurred_at?->toIso8601String(),
            'actor' => $log->actor?->name,
        ], $paginator->items());

        return AIToolResult::success(['events' => $rows, 'total' => $paginator->total()]);
    }
}
