<?php

declare(strict_types=1);

namespace Modules\AI\Domain\ValueObjects;

/**
 * The one bounded, server-built context object handed to the assistant for a
 * single request (§4/§6/§7). `user_id`/`company_id` are ALWAYS resolved from the
 * authenticated server session — never from client input. `brand_id`/
 * `entity_type`/`entity_id` MAY be client-supplied hints, but every tool that
 * uses them must independently re-validate them against the current company
 * before trusting them (§6: "Brand/entity context supplied by the client must
 * be validated before use").
 *
 * Deliberately excludes the user's permission list — the model is never told
 * what a user can or cannot do; each tool call is authorized independently by
 * {@see \Modules\AI\Application\Services\AIToolInvoker} regardless of what this
 * object contains (§4: "context must never become an authorization authority").
 *
 * Built fresh on every request (§7) — never cached or reused across a company
 * or Brand switch.
 */
final class AIRequestContext
{
    public function __construct(
        public readonly int $userId,
        public readonly string $companyId,
        public readonly ?string $brandId,
        public readonly string $locale,
        public readonly ?string $route,
        public readonly ?string $module,
        public readonly ?string $page,
        public readonly ?string $entityType,
        public readonly ?string $entityId,
    ) {}
}
