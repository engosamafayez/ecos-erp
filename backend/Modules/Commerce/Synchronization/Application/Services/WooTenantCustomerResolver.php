<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Organization\Brands\Domain\Models\Brand;
use RuntimeException;

/**
 * TASK-ECOS-V1.1-WOO-02-TENANT-SAFE-CUSTOMER-IDENTITY-044.
 *
 * The ONE canonical company-scoped Customer resolution authority for WooCommerce
 * synchronization — shared by WooCommerceOrderImporter and WooCommerceCustomerSyncer
 * so there is exactly one matching contract, not one per file. Operates exclusively
 * on `Modules\Crm\Customers\Domain\Models\Customer` — the class `Order.php` and all
 * Woo sync code already use (see architecture authority 042A-R1 §4). The parallel
 * `Modules\Sales\Customers\Domain\Models\Customer` class is untouched and unrelated
 * to this resolver; both remain separate, intentional bounded-context wrappers over
 * the same `customers` table.
 *
 * Company resolution (`channel → brand → company`) is moved here verbatim from
 * WooCommerceOrderImporter's former private `resolveCompanyId()` — same fail-closed
 * contract: throws rather than ever returning an unscoped/null company. Every
 * customer lookup and every customer creation this class performs REQUIRES a
 * resolved `company_id` — there is no unscoped code path.
 */
final class WooTenantCustomerResolver
{
    /**
     * The company that owns a Woo integration context, resolved from the channel
     * alone. CHAIN: `channel.brand_id → brands.company_id` — the platform's existing
     * convention for deriving tenancy from a channel. Throws rather than returning an
     * unscoped/null company: an order or customer with no owner is not a lesser row,
     * it is a row no tenant control can see.
     */
    public function resolveCompanyId(Channel $channel): string
    {
        $brandId = $channel->brand_id;

        $companyId = $brandId !== null
            ? Brand::query()->whereKey($brandId)->value('company_id')
            : null;

        if ($companyId === null || (string) $companyId === '') {
            throw new RuntimeException(
                "Channel [{$channel->id}] resolves to no owning company (brand_id is null or its brand is missing). "
                .'Refusing to resolve a customer with no tenant.',
            );
        }

        return (string) $companyId;
    }

    /** Company-scoped match by phone OR mobile. A match in another company is not a match. */
    public function findByPhone(string $companyId, string $normalizedPhone): ?Customer
    {
        if ($normalizedPhone === '') {
            return null;
        }

        return Customer::query()
            ->where('company_id', $companyId)
            ->where(fn ($q) => $q->where('phone', $normalizedPhone)->orWhere('mobile', $normalizedPhone))
            ->first();
    }

    /** Company-scoped match by email. A match in another company is not a match. */
    public function findByEmail(string $companyId, string $email): ?Customer
    {
        if ($email === '') {
            return null;
        }

        return Customer::query()
            ->where('company_id', $companyId)
            ->where('email', $email)
            ->first();
    }

    /**
     * The ONE canonical Woo-originated customer-creation path — always scoped to the
     * given company, always suppressing Eloquent events (the existing, unchanged
     * circular-outbound-sync guard: a customer created FROM WooCommerce must not
     * immediately dispatch an outbound CustomerSyncJob back to the same channel).
     *
     * @param  array<string, mixed>  $attributes  Customer fields (name, email, phone, ...) —
     *                                            never `company_id` or `code`, which this
     *                                            method always sets itself.
     */
    public function createCustomer(string $companyId, array $attributes): Customer
    {
        return Customer::withoutEvents(function () use ($companyId, $attributes): Customer {
            return Customer::query()->create(array_merge($attributes, [
                'code' => $this->nextCustomerCode(),
                'company_id' => $companyId,
            ]));
        });
    }

    /**
     * `customers.code` is globally unique (not company-scoped, verified in the current
     * migration) — this sequence must therefore stay global, moved here verbatim from
     * the two previously-duplicated copies in WooCommerceOrderImporter and
     * WooCommerceCustomerSyncer.
     */
    private function nextCustomerCode(): string
    {
        $last = Customer::query()
            ->withTrashed()
            ->where('code', 'like', 'CUS-%')
            ->orderByRaw("CAST(REPLACE(code, 'CUS-', '') AS UNSIGNED) DESC")
            ->value('code');

        if ($last === null) {
            return 'CUS-001';
        }

        $current = (int) str_replace('CUS-', '', (string) $last);

        return 'CUS-'.str_pad((string) ($current + 1), 3, '0', STR_PAD_LEFT);
    }
}
