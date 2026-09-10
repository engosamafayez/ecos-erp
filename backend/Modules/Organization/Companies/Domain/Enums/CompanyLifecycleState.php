<?php

declare(strict_types=1);

namespace Modules\Organization\Companies\Domain\Enums;

/**
 * TASK-...-026 §1/§17 — the canonical Pre-Live/Live authority. PreLive → destructive test-reset
 * capability may be available; Live → it MUST be blocked, permanently (no approved reversal
 * contract exists, so this task implements none — see CompanyLifecycleAuthority).
 */
enum CompanyLifecycleState: string
{
    case PreLive = 'pre_live';
    case Live = 'live';

    /** Unconfigured/unknown resolves here — every existing company keeps working as it does today. */
    public static function default(): self
    {
        return self::PreLive;
    }

    public static function tryFromValue(?string $value): self
    {
        return $value === null ? self::default() : (self::tryFrom($value) ?? self::default());
    }

    public function label(): string
    {
        return match ($this) {
            self::PreLive => 'Pre-Live',
            self::Live => 'Live',
        };
    }
}
