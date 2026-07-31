<?php

declare(strict_types=1);

namespace Modules\Crm\Sales\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Crm\Customers\Domain\Enums\CustomerType;
use Modules\Crm\Customers\Domain\Services\CustomerService;
use Modules\Crm\Sales\Domain\Enums\LeadStatus;
use Modules\Crm\Sales\Domain\Exceptions\SalesException;
use Modules\Crm\Sales\Domain\Models\Lead;
use Modules\Crm\Sales\Domain\Models\Opportunity;

/**
 * Leads and their conversion.
 *
 * ┌─ CONVERSION BRIDGES TO THE CUSTOMER FOUNDATION ─────────────────────────┐
 * │ A lead is a prospect the CRM owns. Converting a qualified lead creates (or │
 * │ links) a customer via C1 and opens an opportunity — the sales relationship │
 * │ becomes a real customer without ever duplicating identity.                 │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class LeadService
{
    public function __construct(
        private readonly CustomerService $customers,
        private readonly OpportunityService $opportunities,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(string $companyId, array $data, ?int $actorId = null): Lead
    {
        return Lead::create([
            'company_id' => $companyId,
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'company_name' => $data['company_name'] ?? null,
            'source' => $data['source'] ?? null,
            'status' => LeadStatus::New->value,
            'score' => $data['score'] ?? null,
            'owner_id' => $data['owner_id'] ?? $actorId,
            'notes' => $data['notes'] ?? null,
            'tags' => $data['tags'] ?? null,
            'created_by' => $actorId,
        ]);
    }

    public function setStatus(Lead $lead, LeadStatus $status): Lead
    {
        $lead->update(['status' => $status->value]);

        return $lead->refresh();
    }

    /**
     * Convert a lead: create/link a customer, open an opportunity, and close the
     * lead as converted.
     *
     * @param  array<string, mixed>  $opportunity  name, amount, currency, expected_close_date
     * @return array{lead: Lead, customer_id: string, opportunity: Opportunity}
     */
    public function convert(Lead $lead, array $opportunity, ?int $actorId = null, ?string $existingCustomerId = null): array
    {
        if ($lead->isConverted()) {
            throw SalesException::leadAlreadyConverted($lead->name);
        }

        return DB::transaction(function () use ($lead, $opportunity, $actorId, $existingCustomerId): array {
            $customerId = $existingCustomerId ?? $this->createCustomerFromLead($lead, $actorId);

            $opp = $this->opportunities->create((string) $lead->company_id, array_merge([
                'name' => $opportunity['name'] ?? ($lead->name.' — opportunity'),
                'customer_id' => $customerId,
                'lead_id' => $lead->id,
                'source' => $lead->source,
            ], $opportunity), $actorId);

            $lead->update([
                'status' => LeadStatus::Converted->value,
                'customer_id' => $customerId,
                'converted_opportunity_id' => $opp->id,
                'converted_at' => Carbon::now(),
            ]);

            return ['lead' => $lead->refresh(), 'customer_id' => $customerId, 'opportunity' => $opp];
        });
    }

    private function createCustomerFromLead(Lead $lead, ?int $actorId): string
    {
        $isBusiness = ! empty($lead->company_name);
        $customer = $this->customers->create(
            (string) $lead->company_id,
            $isBusiness ? CustomerType::Business : CustomerType::Individual,
            [
                'business_name' => $lead->company_name,
                'first_name' => $isBusiness ? null : $lead->name,
                'name' => $lead->name,
                'phone' => $lead->phone,
                'email' => $lead->email,
            ],
            $actorId,
        );

        return (string) $customer->id;
    }
}
