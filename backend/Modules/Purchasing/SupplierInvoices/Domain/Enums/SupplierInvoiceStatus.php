<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Domain\Enums;

enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case Validated = 'validated';
    case AutoProcessing = 'auto_processing';
    case Posted = 'posted';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Validated => 'Validated',
            self::AutoProcessing => 'Processing…',
            self::Posted => 'Posted',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Validated => 'blue',
            self::AutoProcessing => 'yellow',
            self::Posted => 'green',
            self::Failed => 'red',
            self::Cancelled => 'red',
        };
    }

    /**
     * A Failed invoice may be retried directly (no re-validation required): the underlying
     * cause is almost always an external precondition (e.g. a missing goods-receipt anchor, or
     * Finance account-role mapping not yet configured) that gets fixed OUTSIDE the invoice
     * itself, not by editing it. Without this, a Failed invoice had no path back to Posted
     * except Cancel — a dead end for a routinely-recoverable failure.
     */
    public function canPost(): bool
    {
        return $this === self::Validated || $this === self::Failed;
    }

    public function canCancel(): bool
    {
        return in_array($this, [self::Draft, self::Validated, self::Failed]);
    }
}
