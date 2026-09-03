<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Domain\Services;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§4).
 *
 * The single canonical phone normalization algorithm. Before this class, the only
 * normalization logic anywhere in the backend was a private method on
 * WooCommerceOrderImporter, used by nothing else — Sales\Customers' own "Phone
 * First" duplicate-detection code (CustomerController::checkDuplicatePhone,
 * SearchCustomerByPhoneAction) did raw, unnormalized string comparison. Rather
 * than invent a second algorithm for blocking (forbidden by §4), this class
 * PROMOTES that one existing algorithm to a shared, canonical location —
 * WooCommerceOrderImporter now delegates to it instead of keeping its own copy.
 *
 * Digits-only, E.164-like, no leading '+'. A leading local '0' (Egyptian trunk
 * prefix) is rewritten to the '2' country code, so "01012345678" and
 * "+201012345678" both normalize to "201012345678". This is exactly the pre-
 * existing behaviour — no new format handling is added.
 */
final class PhoneNormalizer
{
    public function normalize(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if ($digits === '') {
            return '';
        }

        if (str_starts_with($digits, '0') && strlen($digits) >= 10) {
            $digits = '2'.$digits;
        }

        return $digits;
    }
}
