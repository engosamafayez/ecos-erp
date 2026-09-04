<?php

declare(strict_types=1);

namespace Modules\Finance\Expenses\Domain\Enums;

/**
 * The lifecycle of a Finance expense document — the exact PaymentStatus
 * shape (draft → approved → posted; void unreachable-by-design, consistent
 * with PaymentStatus::Void/DocumentStatus::Void elsewhere in this codebase):
 * money leaving the business carries the same maker/checker approval step a
 * supplier payment does.
 */
enum ExpenseStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Posted = 'posted';
    case Void = 'void';

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }

    public function isApproved(): bool
    {
        return $this === self::Approved;
    }

    public function isPosted(): bool
    {
        return $this === self::Posted;
    }

    public function isVoid(): bool
    {
        return $this === self::Void;
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
