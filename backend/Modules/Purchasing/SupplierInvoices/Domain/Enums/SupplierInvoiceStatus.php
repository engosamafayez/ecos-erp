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

    /** TASK-...-020 §5/§6 — single authority for "what can be done from here", mirroring the
     *  inline checks that used to live scattered across the controller (update()/validate()/
     *  destroy()), so the frontend can drive its action menu from one server-computed list
     *  instead of re-deriving these same conditions from the raw status string. */
    public function canEdit(): bool
    {
        return $this === self::Draft || $this === self::Failed;
    }

    public function canValidate(): bool
    {
        return $this === self::Draft;
    }

    public function canDelete(): bool
    {
        return $this === self::Draft;
    }

    /**
     * TASK-...-020 §5/§6 — presentation-only projection onto the 6 user-approved words
     * (draft / commercially_approved / partial_received / fully_received / posted / failed /
     * cancelled — "processing" added for the rare case a client reads mid-transaction).
     * $receivingStatus is one of SupplierInvoiceReceivingSummary's own string constants
     * (not_applicable/awaiting/partially_received/reconciled) — passed in rather than resolved
     * here, since that read-model lives in the Application layer and this enum stays Domain-only.
     * This NEVER changes `status` itself or any of the canX() guards above — purely a label.
     */
    public function displayBucket(?string $receivingStatus): string
    {
        return match ($this) {
            self::Draft => 'draft',
            self::Validated => match ($receivingStatus) {
                'partially_received' => 'partial_received',
                'reconciled' => 'fully_received',
                default => 'commercially_approved', // not_applicable (Mode 3) or awaiting
            },
            self::AutoProcessing => 'processing',
            self::Posted => 'posted',
            self::Failed => 'failed',
            self::Cancelled => 'cancelled',
        };
    }
}
