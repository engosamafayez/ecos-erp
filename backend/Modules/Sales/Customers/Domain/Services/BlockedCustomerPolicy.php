<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Domain\Services;

use Illuminate\Support\Collection;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Models\OrderBlockOverride;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009 (§32).
 *
 * THE single read authority for two questions:
 *   - is this company + normalized phone currently blocked?
 *   - does this Order have an approved active override?
 *
 * Deliberately narrow (§32): block lookup, override lookup, eligibility-decision
 * INPUT only. It never writes Order.status, never calls FulfillmentEngine, and
 * knows nothing about workflows — it has no dependency on Operations\Fulfillment
 * at all, matching the direction Commerce\Orders\Domain\Services\
 * PaymentFulfillmentGate already establishes (a Domain Service consulted BY
 * Fulfillment workflows, never the other way round). Fulfillment workflows call
 * isOrderBlocked() themselves and decide what to do about it — see
 * ProcessOrderWorkflow::guard() / ConfirmOrderWorkflow::guard().
 *
 * MATCHING (§29): normalized phone is the sole match key — never Customer id
 * alone, so phone-before-Customer blocking (§10) keeps working, and never fuzzy/
 * partial matching. Candidate phones for an Order are its OWN billing phone
 * first, then its Customer's phone/mobile (§16/§30: a Customer's block does not
 * silently follow it to a brand-new phone; it is the phone identity that carries
 * the block, and an Order's own recorded phone is the truest record of which
 * identity placed it).
 */
final class BlockedCustomerPolicy
{
    /**
     * The one hold-reason code this task writes (§15). Closed vocabulary — see
     * the migration that adds orders.hold_reason_code.
     */
    public const HOLD_REASON_BLOCKED_CUSTOMER = 'blocked_customer';

    public function __construct(private readonly PhoneNormalizer $normalizer) {}

    public function normalize(?string $phone): string
    {
        return $this->normalizer->normalize($phone);
    }

    /** The active block for one company + normalized phone, or null. */
    public function activeBlockForPhone(string $companyId, string $normalizedPhone): ?CustomerBlock
    {
        if ($normalizedPhone === '') {
            return null;
        }

        return CustomerBlock::query()
            ->where('company_id', $companyId)
            ->where('normalized_phone', $normalizedPhone)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Batched active-block lookup for a page of Customers (§33/§54) — ONE query,
     * never one per row.
     *
     * Matches by customer_id OR either saved phone/mobile — NOT customer_id alone.
     * A phone-first block's `customer_id` is only backfilled opportunistically, at
     * the moment something ELSE happens to check it (an order is placed, or the
     * block/unblock action itself runs) — §10's guarantee ("the existing block
     * remains authoritative" the moment a matching Customer exists) must hold even
     * for a Customer that is merely being VIEWED and has never triggered that
     * backfill. Same OR-by-identity shape as EloquentCustomerRepository's
     * `blocked_only` filter, so the list filter and this read-model enrichment
     * can never disagree about which customers are blocked.
     *
     * @param  Collection<int, Customer>  $customers
     * @return array<string, CustomerBlock> keyed by customer_id
     */
    public function activeBlocksForCustomers(Collection $customers, string $companyId): array
    {
        $ids = $customers->map(fn (Customer $c) => (string) $c->id)->filter(fn (string $id) => $id !== '')->unique()->values()->all();

        /** @var array<string, list<string>> normalized phone => customer ids sharing it */
        $phoneToCustomerIds = [];
        foreach ($customers as $customer) {
            foreach ([$customer->phone, $customer->mobile] as $candidate) {
                $normalized = $this->normalizer->normalize($candidate);
                if ($normalized === '') {
                    continue;
                }
                $phoneToCustomerIds[$normalized][] = (string) $customer->id;
            }
        }

        if ($ids === [] && $phoneToCustomerIds === []) {
            return [];
        }

        $blocks = CustomerBlock::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($ids, $phoneToCustomerIds): void {
                if ($ids !== []) {
                    $query->orWhereIn('customer_id', $ids);
                }
                if ($phoneToCustomerIds !== []) {
                    $query->orWhereIn('normalized_phone', array_keys($phoneToCustomerIds));
                }
            })
            // TASK-...-FINAL-UI-CLOSURE-014 (§6) — ONE extra batched query for the whole
            // page (bounded by how many active blocks exist), never one per row/customer.
            ->with(['blockedByUser:id,name,display_name'])
            ->get();

        $result = [];
        foreach ($blocks as $block) {
            if ($block->customer_id !== null) {
                $result[(string) $block->customer_id] ??= $block;
            }
            foreach ($phoneToCustomerIds[$block->normalized_phone] ?? [] as $customerId) {
                $result[$customerId] ??= $block;
            }
        }

        return $result;
    }

    /**
     * Same batched lookup as {@see self::activeBlocksForCustomers()}, but for a
     * caller holding plain identity data rather than a Sales\Customers Collection
     * — e.g. CRM Portfolio (TASK-ECOS-CRM-CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003
     * §13), which resolves customers through the canonical `Crm\Customers` class
     * (the same physical `customers` table, the same `phone`/`mobile` columns),
     * never this module's own Customer class. Read-only, same match rules, same
     * owning module — not a second policy.
     *
     * @param  list<array{id: string, phone: ?string, mobile: ?string}>  $identities
     * @return array<string, CustomerBlock> keyed by customer_id
     */
    public function activeBlocksForIdentities(array $identities, string $companyId): array
    {
        $ids = [];

        /** @var array<string, list<string>> normalized phone => customer ids sharing it */
        $phoneToCustomerIds = [];
        foreach ($identities as $identity) {
            $id = (string) ($identity['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }

            foreach ([$identity['phone'] ?? null, $identity['mobile'] ?? null] as $candidate) {
                $normalized = $this->normalizer->normalize($candidate);
                if ($normalized === '') {
                    continue;
                }
                $phoneToCustomerIds[$normalized][] = $id;
            }
        }

        $ids = array_values(array_unique($ids));

        if ($ids === [] && $phoneToCustomerIds === []) {
            return [];
        }

        $blocks = CustomerBlock::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($query) use ($ids, $phoneToCustomerIds): void {
                if ($ids !== []) {
                    $query->orWhereIn('customer_id', $ids);
                }
                if ($phoneToCustomerIds !== []) {
                    $query->orWhereIn('normalized_phone', array_keys($phoneToCustomerIds));
                }
            })
            ->with(['blockedByUser:id,name,display_name'])
            ->get();

        $result = [];
        foreach ($blocks as $block) {
            if ($block->customer_id !== null) {
                $result[(string) $block->customer_id] ??= $block;
            }
            foreach ($phoneToCustomerIds[$block->normalized_phone] ?? [] as $customerId) {
                $result[$customerId] ??= $block;
            }
        }

        return $result;
    }

    /**
     * Candidate normalized phones for an Order, in the preference order §29
     * establishes: the Order's OWN recorded phone first, then its Customer's
     * phone/mobile as a fallback for Orders written before billing_phone was
     * captured.
     *
     * @return list<string>
     */
    public function candidatePhonesForOrder(Order $order): array
    {
        $order->loadMissing('customer');

        $raw = [$order->billing_phone, $order->customer?->phone, $order->customer?->mobile];
        $normalized = array_map(fn (?string $p) => $this->normalizer->normalize($p), $raw);

        return array_values(array_unique(array_filter($normalized, static fn (string $p) => $p !== '')));
    }

    public function activeBlockForOrder(Order $order): ?CustomerBlock
    {
        $companyId = (string) $order->company_id;

        foreach ($this->candidatePhonesForOrder($order) as $phone) {
            $block = $this->activeBlockForPhone($companyId, $phone);

            if ($block !== null) {
                return $block;
            }
        }

        return null;
    }

    public function hasActiveOverride(string $orderId): bool
    {
        return OrderBlockOverride::query()->where('order_id', $orderId)->exists();
    }

    public function activeOverrideForOrder(string $orderId): ?OrderBlockOverride
    {
        return OrderBlockOverride::query()->where('order_id', $orderId)->first();
    }

    /**
     * Is this Order currently subject to the block — an active block matches it
     * AND no one-order override has been granted (§26/§32)?
     */
    public function isOrderBlocked(Order $order): bool
    {
        if ($this->activeBlockForOrder($order) === null) {
            return false;
        }

        return ! $this->hasActiveOverride((string) $order->id);
    }

    /**
     * Full block/unblock history for a Customer (§6/§41) — every episode matching
     * either its customer_id (bound, opportunistically or at creation) OR any
     * phone/mobile it currently holds, so a phone-first block never disappears
     * from the history just because the binding backfill hadn't run yet when it
     * was unblocked. Newest first.
     *
     * @param  list<string|null>  $phones
     * @return Collection<int, CustomerBlock>
     */
    public function historyForCustomer(string $customerId, string $companyId, array $phones): Collection
    {
        $normalizedPhones = array_values(array_unique(array_filter(
            array_map(fn (?string $p) => $this->normalizer->normalize($p), $phones),
            static fn (string $p) => $p !== '',
        )));

        return CustomerBlock::query()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($customerId, $normalizedPhones): void {
                $q->where('customer_id', $customerId);
                if ($normalizedPhones !== []) {
                    $q->orWhereIn('normalized_phone', $normalizedPhones);
                }
            })
            // TASK-...-FINAL-UI-CLOSURE-014 (§5/§6) — ONE extra batched query for this
            // customer's whole history, never one per history row.
            ->with(['blockedByUser:id,name,display_name', 'unblockedByUser:id,name,display_name'])
            ->orderByDesc('blocked_at')
            ->get();
    }
}
