<?php

declare(strict_types=1);

namespace Modules\AI\Domain\ValueObjects;

/**
 * A trusted, server-generated pointer back to a canonical ECOS record (§12/§22)
 * — e.g. "Order #123" the frontend can render as a clickable navigation target.
 * Always constructed by tool code from data it already fetched and authorized;
 * the model never invents or supplies one.
 */
final class AIEntityReference
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $label,
        public readonly string $route,
    ) {}

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['type' => $this->type, 'id' => $this->id, 'label' => $this->label, 'route' => $this->route];
    }
}
