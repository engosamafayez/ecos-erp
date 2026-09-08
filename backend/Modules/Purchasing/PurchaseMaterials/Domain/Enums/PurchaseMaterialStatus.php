<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseMaterials\Domain\Enums;

enum PurchaseMaterialStatus: string
{
    // ── Workflow states ────────────────────────────────────────────────────
    case Draft = 'draft';
    case UnderReview = 'under_review';
    case WaitingSupplierSelection = 'waiting_supplier_selection';
    case Approved = 'approved';
    case Purchasing = 'purchasing';
    case Receiving = 'receiving';
    case Completed = 'completed';

    // ── Exception states ───────────────────────────────────────────────────
    case Rejected = 'rejected';
    case OnHold = 'on_hold';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::UnderReview => 'Under Review',
            self::WaitingSupplierSelection => 'Waiting Supplier Selection',
            self::Approved => 'Approved',
            self::Purchasing => 'Purchasing',
            self::Receiving => 'Receiving',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::OnHold => 'On Hold',
            self::Cancelled => 'Cancelled',
        };
    }

    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::OnHold], true);
    }

    public function canSubmit(): bool
    {
        return $this === self::Draft;
    }

    public function canApprove(): bool
    {
        return $this === self::WaitingSupplierSelection;
    }

    public function canReject(): bool
    {
        return in_array($this, [self::UnderReview, self::WaitingSupplierSelection], true);
    }

    public function canHold(): bool
    {
        return in_array($this, [
            self::Draft,
            self::UnderReview,
            self::WaitingSupplierSelection,
            self::Approved,
        ], true);
    }

    public function canCancel(): bool
    {
        return in_array($this, [
            self::Draft,
            self::UnderReview,
            self::WaitingSupplierSelection,
            self::OnHold,
        ], true);
    }

    /** TASK-...-011 §4: on_hold was a dead end — nothing ever moved a request back out of it. */
    public function canResume(): bool
    {
        return $this === self::OnHold;
    }

    /** Supplier commitments (SelectLineSupplierAction) are accepted from these states. */
    public function canSelectSupplier(): bool
    {
        return in_array($this, [self::WaitingSupplierSelection, self::Approved, self::Purchasing], true);
    }

    /** Move to the next workflow state. Returns null if no forward transition. */
    public function nextWorkflowState(): ?self
    {
        return match ($this) {
            self::Draft => self::UnderReview,
            self::UnderReview => self::WaitingSupplierSelection,
            self::WaitingSupplierSelection => self::Approved,
            self::Approved => self::Purchasing,
            self::Purchasing => self::Receiving,
            self::Receiving => self::Completed,
            default => null,
        };
    }

    /**
     * Single source of truth for which action tokens are valid from this status.
     *
     * TASK-ECOS-PROCUREMENT-PURCHASE-REQUESTS-AND-HUB-FINAL-REMEDIATION-011 §6/§12: the table and
     * detail page must offer exactly the actions the backend will actually accept — never a button
     * that 422s, never a missing button for something that would succeed. Every screen renders off
     * this one list instead of re-deriving its own guard rules.
     *
     * @return list<string>
     */
    public function availableActions(): array
    {
        $actions = [];

        if ($this->canSubmit()) {
            $actions[] = 'submit';
        }
        if ($this->canSelectSupplier()) {
            $actions[] = 'select_supplier';
        }
        if ($this->canApprove()) {
            $actions[] = 'approve';
        }
        if ($this->canReject()) {
            $actions[] = 'reject';
        }
        if ($this->canHold()) {
            $actions[] = 'hold';
        }
        if ($this->canResume()) {
            $actions[] = 'resume';
        }
        if ($this->canCancel()) {
            $actions[] = 'cancel';
        }

        return $actions;
    }
}
