<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Actions;

use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Synchronization\Application\Services\WooTenantCustomerResolver;
use Modules\Crm\Customers\Domain\Models\Customer;

/**
 * TASK-ECOS-V1.1-WOO-02-TENANT-SAFE-CUSTOMER-IDENTITY-044 §11 — conservative,
 * read-mostly remediation for Orders written BEFORE this task, when customer
 * matching was unscoped: an Order's `customer_id` may point to a Customer row
 * that belongs to a DIFFERENT company than the Order itself.
 *
 * This never merges, deletes, or moves a Customer row — a mismatched Customer
 * is left exactly as it is, in whatever company it already belongs to. The only
 * write this ever makes is repairing the single `Order.customer_id` FK to point
 * at a customer within the Order's OWN company, and only when that repair target
 * can be determined without guessing:
 *
 *   SAFE_AUTO_RELINK — the mismatched customer has an email, phone, or mobile to
 *     key a same-company match (or create) on. The Order is repointed at the
 *     existing same-company customer sharing that identifier, or a new same-
 *     company customer created via the same canonical WooTenantCustomerResolver
 *     every live sync path uses (no second creation mechanism).
 *
 *   NEEDS_REVIEW — the mismatched customer has no email/phone/mobile at all.
 *     Nothing to key a repair on without guessing, so this is surfaced for a
 *     human and never auto-acted on, at any apply setting.
 *
 * Idempotent by construction: once relinked, the Order's customer belongs to the
 * Order's own company, so it no longer matches the mismatch condition on any
 * later run — re-running after an apply is always safe and a no-op for rows
 * already repaired.
 *
 * Dry-run by default ($apply = false): classifies and reports only. Every
 * SAFE_AUTO_RELINK repair actually applied is recorded via OrderEvent::log() —
 * the platform's existing order-audit-trail mechanism (see
 * ReprocessLegacyReservationsCommand for the equivalent precedent) — not a new
 * logging channel.
 */
final class AuditWooCustomerCompanyLinkageAction
{
    public function __construct(
        private readonly WooTenantCustomerResolver $tenantResolver,
    ) {}

    /**
     * @return array{
     *     scanned: int,
     *     mismatched: int,
     *     safe_auto_relink: int,
     *     needs_review: int,
     *     relinked: int,
     *     cases: list<array<string, mixed>>,
     * }
     */
    public function run(bool $apply = false): array
    {
        $scanned = 0;
        $cases = [];
        $safeCount = 0;
        $needsReviewCount = 0;
        $relinkedCount = 0;

        // This audit's entire purpose is cross-company detection, so it must see
        // every company's Orders regardless of the executing actor — the 'tenant'
        // global scope (Order::booted()) is bypassed deliberately and only here,
        // not weakened for any other caller.
        $orders = Order::query()
            ->withoutGlobalScope('tenant')
            ->whereNotNull('company_id')
            ->whereNotNull('customer_id')
            ->with('customer')
            ->cursor();

        foreach ($orders as $order) {
            $scanned++;

            /** @var Customer|null $linkedCustomer */
            $linkedCustomer = $order->customer;

            // Dangling FK (customer row hard-deleted) — a data-integrity question,
            // not a cross-company identity question. Not this audit's concern.
            if ($linkedCustomer === null) {
                continue;
            }

            if ((string) $linkedCustomer->company_id === (string) $order->company_id) {
                continue;
            }

            $case = $this->classify($order, $linkedCustomer);

            if ($case['classification'] === 'SAFE_AUTO_RELINK') {
                $safeCount++;

                if ($apply) {
                    $case = $this->relink($order, $linkedCustomer, $case);
                    $relinkedCount++;
                }
            } else {
                $needsReviewCount++;
            }

            $cases[] = $case;
        }

        return [
            'scanned' => $scanned,
            'mismatched' => count($cases),
            'safe_auto_relink' => $safeCount,
            'needs_review' => $needsReviewCount,
            'relinked' => $relinkedCount,
            'cases' => $cases,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function classify(Order $order, Customer $linkedCustomer): array
    {
        $base = [
            'order_id' => (string) $order->id,
            'order_number' => (string) $order->order_number,
            'order_company_id' => (string) $order->company_id,
            'linked_customer_id' => (string) $linkedCustomer->id,
            'linked_customer_company_id' => (string) $linkedCustomer->company_id,
        ];

        $hasIdentifier = trim((string) $linkedCustomer->email) !== ''
            || trim((string) $linkedCustomer->phone) !== ''
            || trim((string) $linkedCustomer->mobile) !== '';

        if (! $hasIdentifier) {
            return $base + [
                'classification' => 'NEEDS_REVIEW',
                'reason' => 'Linked customer has no email, phone, or mobile to key a same-company match or create on.',
            ];
        }

        return $base + ['classification' => 'SAFE_AUTO_RELINK', 'reason' => null];
    }

    /**
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function relink(Order $order, Customer $wrongCompanyCustomer, array $case): array
    {
        $companyId = (string) $order->company_id;

        $target = $this->tenantResolver->findByPhone($companyId, (string) $wrongCompanyCustomer->phone)
            ?? $this->tenantResolver->findByPhone($companyId, (string) $wrongCompanyCustomer->mobile)
            ?? $this->tenantResolver->findByEmail($companyId, (string) $wrongCompanyCustomer->email);

        $wasCreated = false;

        if ($target === null) {
            $target = $this->tenantResolver->createCustomer($companyId, [
                'name' => $wrongCompanyCustomer->name,
                'email' => $wrongCompanyCustomer->email,
                'phone' => $wrongCompanyCustomer->phone,
                'mobile' => $wrongCompanyCustomer->mobile,
                'city' => $wrongCompanyCustomer->city,
                'country' => $wrongCompanyCustomer->country,
                'address' => $wrongCompanyCustomer->address,
                'is_active' => true,
            ]);
            $wasCreated = true;
        }

        $previousCustomerId = (string) $order->customer_id;

        $order->update(['customer_id' => $target->id]);

        OrderEvent::log(
            orderId: (string) $order->id,
            type: 'woo_customer_linkage_relinked',
            description: "Order relinked from cross-company customer [{$previousCustomerId}] ".
                "(company [{$wrongCompanyCustomer->company_id}]) to same-company customer [{$target->id}] ".
                '— TASK-ECOS-V1.1-WOO-02-TENANT-SAFE-CUSTOMER-IDENTITY-044 historical audit.',
            payload: [
                'previous_customer_id' => $previousCustomerId,
                'previous_customer_company_id' => (string) $wrongCompanyCustomer->company_id,
                'new_customer_id' => (string) $target->id,
                'new_customer_was_created' => $wasCreated,
                'order_company_id' => $companyId,
            ],
            module: 'commerce_synchronization',
            actionType: 'woo_customer_linkage_audit',
        );

        return $case + [
            'relinked_to_customer_id' => (string) $target->id,
            'relinked_customer_was_created' => $wasCreated,
        ];
    }
}
