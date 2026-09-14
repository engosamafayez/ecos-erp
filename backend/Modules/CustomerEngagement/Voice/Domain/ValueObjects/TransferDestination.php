<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\ValueObjects;

/**
 * TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §4 — the ONE provider-neutral
 * shape HumanTransferService is allowed to hand to TelephonyProviderContract::bridgeTransfer().
 * Never a User/Employee/Team model, never a bare internal id pretending to be a phone number —
 * `phoneNumber` is always a normalized (PhoneNormalizer), already-validated dialable string by
 * the time this object exists; `referenceType`/`referenceId` are carried only for audit/display,
 * never passed to the provider.
 */
final class TransferDestination
{
    public function __construct(
        public readonly string $referenceType,
        public readonly string $referenceId,
        public readonly string $phoneNumber,
    ) {}
}
