<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Services\PhoneNormalizer;

/**
 * Handles inbound WooCommerce → ECOS customer synchronization.
 *
 * TASK-ECOS-V1.1-WOO-02-TENANT-SAFE-CUSTOMER-IDENTITY-044 — every lookup and every
 * creation is scoped to the channel's resolved company via the shared
 * WooTenantCustomerResolver (the same authority WooCommerceOrderImporter::resolveCustomer()
 * uses), so there is exactly one matching contract for Woo-originated customers, not one
 * per file. A phone/email match belonging to a different company is not a match; this
 * class never falls back to an unscoped/global lookup, and never creates a customer with
 * no company_id.
 *
 * All Customer model mutations use withoutEvents() (inside WooTenantCustomerResolver for
 * creation, and directly here for update) to prevent CustomerObserver from dispatching a
 * circular outbound CustomerSyncJob back to WooCommerce.
 */
final class WooCommerceCustomerSyncer
{
    public function __construct(
        private readonly WooTenantCustomerResolver $tenantResolver,
        private readonly PhoneNormalizer $phoneNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{action: string, customer_id: string|null}
     */
    public function sync(Channel $channel, array $payload): array
    {
        $billing = is_array($payload['billing'] ?? null) ? $payload['billing'] : [];
        $email = trim((string) ($billing['email'] ?? ($payload['email'] ?? '')));
        $firstName = trim((string) ($billing['first_name'] ?? ''));
        $lastName = trim((string) ($billing['last_name'] ?? ''));
        $name = trim("{$firstName} {$lastName}");

        if ($name === '') {
            $name = $email !== '' ? $email : 'WooCommerce Customer';
        }

        $rawPhone = trim((string) ($billing['phone'] ?? ''));
        $normalizedPhone = $rawPhone !== '' ? $this->phoneNormalizer->normalize($rawPhone) : '';
        $city = trim((string) ($billing['city'] ?? ''));
        $country = trim((string) ($billing['country'] ?? ''));
        $address = trim((string) ($billing['address_1'] ?? ''));

        // Nothing to key a match — or a new record — on. Unlike the former email-only
        // gate, a phone-only payload (no email) is no longer skipped: the company-scoped
        // phone lookup below is just as valid a match/create key as email.
        if ($email === '' && $normalizedPhone === '') {
            return ['action' => 'skipped_no_identifier', 'customer_id' => null];
        }

        // TASK-...-044 — company resolved from the channel FIRST; every lookup and
        // creation below is scoped to it. Throws (fail-closed) rather than ever
        // matching/creating against an unscoped/global customer set; the caller
        // (ProcessCustomerWebhookJob) already treats a thrown exception here as a
        // recorded sync-log failure via its existing try/catch — no new error-handling
        // convention is introduced.
        $companyId = $this->tenantResolver->resolveCompanyId($channel);

        $existing = $this->tenantResolver->findByPhone($companyId, $normalizedPhone)
            ?? $this->tenantResolver->findByEmail($companyId, $email);

        if ($existing !== null) {
            Customer::withoutEvents(function () use ($existing, $name, $normalizedPhone, $city, $country, $address): void {
                $updates = ['name' => $name];

                if ($normalizedPhone !== '') {
                    $updates['phone'] = $normalizedPhone;
                }
                if ($city !== '') {
                    $updates['city'] = $city;
                }
                if ($country !== '') {
                    $updates['country'] = $country;
                }
                if ($address !== '') {
                    $updates['address'] = $address;
                }

                $existing->update($updates);
            });

            return ['action' => 'updated', 'customer_id' => $existing->id];
        }

        $created = $this->tenantResolver->createCustomer($companyId, [
            'name' => $name,
            'email' => $email !== '' ? $email : null,
            'phone' => $normalizedPhone !== '' ? $normalizedPhone : null,
            'city' => $city !== '' ? $city : null,
            'country' => $country !== '' ? $country : null,
            'address' => $address !== '' ? $address : null,
            'is_active' => true,
        ]);

        return ['action' => 'created', 'customer_id' => $created->id];
    }
}
