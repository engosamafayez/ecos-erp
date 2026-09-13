<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Core\Audit\AuditQueryService;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CORE-02 Task 2 §2/§5 — the one read/search endpoint over the central Audit trail.
 * Route-gated on `system.audit.view` (see routes/api.php); company scoping and the
 * unrestricted-actor exception are enforced inside {@see AuditQueryService}, not here.
 */
final class AuditLogController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly AuditQueryService $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer'],
            'action' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'entity_id' => ['nullable', 'string', 'max:36'],
            'company_id' => ['nullable', 'string', 'max:36'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        $page = max(1, (int) $request->query('page', 1));
        $perPage = max(1, (int) $request->query('per_page', 25));

        $paginator = $this->audit->search($filters, $page, $perPage);

        // §4/§9 — project the minimum actor identity an operator needs (name/email), never
        // the raw User model. `$hidden` (password, remember_token) already protects the
        // model itself, but the point here is narrowness, not just secrecy: nothing this
        // read surface exposes should go beyond "who did this and how to recognise them".
        $items = array_map(static fn ($log) => [
            'id' => $log->id,
            'company_id' => $log->company_id,
            'action' => $log->action,
            'entity_type' => $log->entity_type,
            'entity_id' => $log->entity_id,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'metadata' => $log->metadata,
            'occurred_at' => $log->occurred_at?->toIso8601String(),
            'actor' => $log->actor === null ? null : [
                'id' => $log->actor->id,
                'name' => $log->actor->name,
                'email' => $log->actor->email,
            ],
        ], $paginator->items());

        return $this->success([
            'items' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
