<?php

declare(strict_types=1);

namespace Modules\Crm\Engagement\Domain\Enums;

/**
 * A CRM actionable's priority — CTO-ratified V1 set (TASK-ECOS-CRM-CUSTOMER-
 * PORTFOLIO-AND-FOLLOWUP-003 §7), mirroring the sibling `Crm\Service\TicketPriority`
 * pattern. `CustomerTask.priority` stays a raw string column (§7 forbids a
 * migration to convert it) — this enum is the validation/comparison contract
 * over that existing storage, not a new schema. Use {@see self::tryFrom()}
 * to read a stored value; never {@see self::from()}, which throws on a
 * historical value outside this set (§8 — those must stay readable).
 */
enum TaskPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Normal => 2,
            self::High => 3,
            self::Urgent => 4,
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c) => $c->value, self::cases());
    }
}
