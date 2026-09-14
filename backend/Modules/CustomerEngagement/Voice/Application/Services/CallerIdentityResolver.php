<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\CustomerEngagement\Domain\Models\Lead;
use Modules\CustomerEngagement\Voice\Domain\ValueObjects\CallerIdentity;
use Modules\Sales\Customers\Domain\Services\PhoneNormalizer;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §13 — the approved lookup
 * hierarchy: normalized phone -> Customer -> cep_leads Lead -> unknown. Reuses the canonical
 * PhoneNormalizer (Sales\Customers, already Egyptian-trunk-prefix-aware — "01…" <-> "+20 1…" —
 * not a new normalization algorithm) and its `sqlExpression()` for the query-time comparison,
 * exactly as EloquentCustomerRepository's own blocked_only filter already does; never creates a
 * duplicate Customer.
 */
final class CallerIdentityResolver
{
    public function __construct(private readonly PhoneNormalizer $phoneNormalizer) {}

    public function resolve(string $companyId, string $rawPhone): CallerIdentity
    {
        $normalized = $this->phoneNormalizer->normalize($rawPhone);

        if ($normalized === '') {
            return CallerIdentity::unknown();
        }

        $customer = Customer::query()
            ->where('company_id', $companyId)
            ->whereRaw(PhoneNormalizer::sqlExpression('phone').' = ?', [$normalized])
            ->first();

        if ($customer !== null) {
            return CallerIdentity::customer($customer->id);
        }

        $lead = Lead::query()
            ->where('company_id', $companyId)
            ->whereRaw(PhoneNormalizer::sqlExpression('customer_phone').' = ?', [$normalized])
            ->first();

        if ($lead !== null) {
            return CallerIdentity::lead($lead->id);
        }

        return CallerIdentity::unknown();
    }
}
