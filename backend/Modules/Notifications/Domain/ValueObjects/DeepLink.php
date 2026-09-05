<?php

declare(strict_types=1);

namespace Modules\Notifications\Domain\ValueObjects;

/**
 * ADR-047 §8: a typed reference, never a raw frontend URL. The destination endpoint
 * re-authorizes independently at render time — this value object carries a pointer,
 * never a capability grant.
 */
final readonly class DeepLink
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public ?string $actionKey = null,
        public ?string $route = null,
    ) {}

    /** @return array{entity_type: string, entity_id: string, action_key: ?string, route: ?string} */
    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'action_key' => $this->actionKey,
            'route' => $this->route,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            entityType: (string) $data['entity_type'],
            entityId: (string) $data['entity_id'],
            actionKey: isset($data['action_key']) ? (string) $data['action_key'] : null,
            route: isset($data['route']) ? (string) $data['route'] : null,
        );
    }
}
