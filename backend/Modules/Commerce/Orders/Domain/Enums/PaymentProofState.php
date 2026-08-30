<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Enums;

/**
 * Payment-proof lifecycle state — TASK-PAYMENT-PROOF-LIFECYCLE-001 §3.
 *
 * These are NOT Order statuses and NOT payment states. A proof is evidence:
 * its state never marks a payment PAID and never moves the order lifecycle.
 */
enum PaymentProofState: string
{
    case Uploaded = 'uploaded';
    case Verified = 'verified';
    case Rejected = 'rejected';
}
