<?php

declare(strict_types=1);

namespace Modules\Crm\Customers\Domain\Services;

use Modules\Crm\Customers\Domain\Models\Customer;

/**
 * Customer 360° — the complete profile of a customer's OWN identity data.
 *
 * ┌─ CUSTOMER-OWNED DATA ONLY · DEPENDENCY DIRECTION PRESERVED ─────────────┐
 * │ This aggregates the identity the Customer domain owns: the master record,  │
 * │ groups, all contacts and addresses, tags, notes, documents, preferences    │
 * │ and merge lineage. It deliberately imports NO operational module — Commerce │
 * │ Finance, Logistics and Marketing reference the customer and each builds     │
 * │ its own 360° panel; the dependency never inverts.                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class Customer360Service
{
    /** @return array<string, mixed> */
    public function profile(Customer $customer): array
    {
        $customer->loadMissing(['group', 'phones', 'emails', 'addresses', 'tags', 'customerNotes', 'documents', 'preferences', 'mergedInto']);

        return [
            'identity' => $this->identity($customer),
            'group' => $customer->group !== null ? ['id' => $customer->group->id, 'name' => $customer->group->name] : null,
            'phones' => $customer->phones->map(fn ($p) => [
                'id' => $p->id, 'label' => $p->label, 'phone' => $p->phone, 'is_primary' => (bool) $p->is_primary, 'is_verified' => (bool) $p->is_verified,
            ])->all(),
            'emails' => $customer->emails->map(fn ($e) => [
                'id' => $e->id, 'label' => $e->label, 'email' => $e->email, 'is_primary' => (bool) $e->is_primary, 'is_verified' => (bool) $e->is_verified,
            ])->all(),
            'addresses' => $customer->addresses->map(fn ($a) => [
                'id' => $a->id, 'label' => $a->label, 'governorate' => $a->governorate, 'city' => $a->city, 'area' => $a->area,
                'address_line' => $a->address_line, 'is_default' => (bool) $a->is_default,
            ])->all(),
            'tags' => $customer->tags->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'color' => $t->color])->all(),
            'notes' => $customer->customerNotes->sortByDesc('is_pinned')->values()->map(fn ($n) => [
                'id' => $n->id, 'body' => $n->body, 'is_pinned' => (bool) $n->is_pinned, 'author_id' => $n->author_id, 'created_at' => $n->created_at?->toIso8601String(),
            ])->all(),
            'documents' => $customer->documents->map(fn ($d) => [
                'id' => $d->id, 'name' => $d->name, 'doc_type' => $d->doc_type, 'mime_type' => $d->mime_type, 'size_bytes' => $d->size_bytes,
            ])->all(),
            'preferences' => $customer->preferences->pluck('value', 'key')->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function identity(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'code' => $customer->code,
            'company_id' => $customer->company_id,
            'type' => $customer->customer_type?->value,
            'display_name' => $customer->displayName(),
            'first_name' => $customer->first_name,
            'last_name' => $customer->last_name,
            'business_name' => $customer->business_name,
            'tax_registration_number' => $customer->tax_registration_number,
            'status' => $customer->status?->value,
            'is_active' => (bool) $customer->is_active,
            'primary_phone' => $customer->phone,
            'primary_email' => $customer->email,
            'preferred_language' => $customer->preferred_language,
            'preferred_contact_method' => $customer->preferred_contact_method,
            'merged_into_id' => $customer->merged_into_id,
            'archived_at' => $customer->archived_at?->toIso8601String(),
            'full_address' => $this->fullAddress($customer),
            'location' => $this->location($customer),
        ];
    }

    /**
     * The customer's address as one display string.
     *
     * SOURCE PRECEDENCE — the structured `customer_addresses` default row wins over the
     * denormalised `customers.*` columns. Both exist and they can DISAGREE: the two are
     * written by different paths (the CRM address book vs. the order-intake/Woo sync),
     * and the master columns are the legacy pair. Reading them in this order means the
     * list shows the same address the 360° profile's `addresses[]` shows, rather than a
     * second, quietly different answer.
     *
     * NOTE: the precedence itself is an engineering choice, not a ratified business rule.
     * If the business considers `customers.address` authoritative, only this method and
     * {@see self::location()} change.
     */
    private function fullAddress(Customer $customer): ?string
    {
        $default = $this->defaultAddress($customer);

        $parts = $default !== null
            ? [$default->address_line, $default->area, $default->city, $default->governorate]
            : [$customer->address, $customer->area, $customer->city, $customer->governorate];

        $parts = array_values(array_filter(
            array_map(static fn ($p) => is_string($p) ? trim($p) : null, $parts),
            static fn (?string $p) => $p !== null && $p !== '',
        ));

        return $parts === [] ? null : implode('، ', $parts);
    }

    /**
     * The default address row, resolving it if the caller did not eager-load it.
     *
     * The list eager-loads it once for the whole page, so this never fires there. On the
     * single-record paths (`show`, `profile`) it costs one query — the alternative is the
     * same field answering differently per endpoint, which is worse than one query.
     */
    private function defaultAddress(Customer $customer): ?object
    {
        if (! $customer->relationLoaded('addresses')) {
            $customer->load(['addresses' => fn ($a) => $a->where('is_default', true)]);
        }

        return $customer->addresses->firstWhere('is_default', true);
    }

    /** City / governorate only — the coarse "where is this customer" column. */
    private function location(Customer $customer): ?string
    {
        $default = $this->defaultAddress($customer);

        $city = $default !== null ? $default->city : $customer->city;
        $governorate = $default !== null ? $default->governorate : $customer->governorate;

        $parts = array_values(array_filter(
            array_map(static fn ($p) => is_string($p) ? trim($p) : null, [$city, $governorate]),
            static fn (?string $p) => $p !== null && $p !== '',
        ));

        return $parts === [] ? null : implode('، ', $parts);
    }
}
