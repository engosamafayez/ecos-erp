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

    /**
     * TASK-...-009-R1 (§4) — the SQL equivalent of normalize(), for the one
     * comparison that cannot pull every row into PHP first:
     * EloquentCustomerRepository's `blocked_only` filter has to decide, at query
     * time and before pagination, which Customers match a KNOWN set of already-
     * normalized block phones. Every other consumer of this class (the block
     * write actions, BlockedCustomerPolicy's per-page read-model enrichment) has
     * the candidate Customer rows already in hand and calls normalize() in PHP —
     * this is the sole exception, not a second algorithm.
     *
     * MUST stay in sync with normalize() above — same two rules, restated in SQL:
     * strip everything but digits, then rewrite a leading local '0' to the '2'
     * country code once the digit-only form is 10+ characters long. `$column`
     * must be a trusted column identifier (a literal string from calling code,
     * never user input) — it is interpolated directly into the returned raw SQL.
     */
    public static function sqlExpression(string $column): string
    {
        $digits = "REGEXP_REPLACE(COALESCE({$column}, ''), '[^0-9]', '')";

        return "CASE WHEN {$digits} REGEXP '^0[0-9]{9,}$' THEN CONCAT('2', {$digits}) ELSE {$digits} END";
    }
}
