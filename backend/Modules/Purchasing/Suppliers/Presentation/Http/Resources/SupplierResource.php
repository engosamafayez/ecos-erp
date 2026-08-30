<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

/**
 * @mixin Supplier
 */
final class SupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'contact_person' => $this->contact_person,
            'email' => $this->email,
            'phone' => $this->phone,
            'mobile' => $this->mobile,
            // Location (Part 1)
            'country' => $this->country,
            'state' => $this->state,
            'city' => $this->city,
            'district' => $this->district,
            'address' => $this->address,
            'google_maps_url' => $this->google_maps_url,

            // Opening balance (Part 2) + Previous balance (Part 3) — the balance carried
            // into ECOS at onboarding. Signed: credit is payable to the supplier (+),
            // debit is receivable / prepaid (−).
            'opening_balance_amount' => round((float) $this->opening_balance_amount, 2),
            'opening_balance_type' => $this->opening_balance_type ?? 'credit',
            'opening_balance' => $this->openingBalanceSigned(),
            'previous_balance' => $this->openingBalanceSigned(),

            'notes' => $this->notes,
            'is_active' => (bool) $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Aggregate columns — populated when fetched via the list endpoint (LEFT JOIN subqueries).
            // Null-safe: will be null on single-record fetches that don't include the joins.
            'total_invoiced' => $this->whenHas('total_invoiced', fn () => round((float) $this->total_invoiced, 2)),
            'total_paid' => $this->whenHas('total_paid', fn () => round((float) $this->total_paid, 2)),
            'outstanding_balance' => $this->whenHas('outstanding_balance', fn () => round(max(0.0, (float) $this->outstanding_balance), 2)),
            'last_purchase_date' => $this->whenHas('last_purchase_date', fn () => $this->last_purchase_date),
            'active_pos_count' => $this->whenHas('active_pos_count', fn () => (int) $this->active_pos_count),
            'inventory_cost_value' => $this->whenHas('inventory_cost_value', fn () => round((float) $this->inventory_cost_value, 2)),

            // ── Grid financial columns — LEDGER-DERIVED (REALIGNMENT-001 §16) ──────────
            // These used to be computed from hand-entered goods-receipt scalars, and
            // current_supplier_balance additionally ADDED the suppliers.opening_balance_amount
            // scalar on top — double-counting every opening balance that the certified
            // SupplierOpeningBalanceService had already posted to the AP subledger. The money
            // columns now come from the ledger (the SSOT), identical to Supplier 360, so the
            // list and the drawer can no longer disagree. Purchase VOLUME metrics
            // (total_invoiced / total_paid / total_purchased_value) remain receipt-derived —
            // they describe what was bought, not what is owed.
            'purchase_balance' => $this->whenHas('total_invoiced', fn () => round((float) $this->total_invoiced, 2)),
            'total_purchased_value' => $this->whenHas('total_invoiced', fn () => round((float) $this->total_invoiced, 2)),

            // What we owe the supplier: opening payable + bills − payments − credit notes.
            // Advances are EXCLUDED here by contract and reported on their own line below.
            'total_outstanding' => $this->whenHas('ledger_outstanding_payable', fn () => round((float) $this->ledger_outstanding_payable, 2)),
            'current_supplier_balance' => $this->whenHas('ledger_outstanding_payable', fn () => round((float) $this->ledger_outstanding_payable, 2)),

            // A prepaid credit held at the supplier — never shown as debt, never netted
            // into the payable (TASK-PROC-SUPPLIER-OPENING-BALANCE-001).
            'available_advance' => $this->whenHas('ledger_available_advance', fn () => round((float) $this->ledger_available_advance, 2)),

            // The opening payable actually posted to the ledger at onboarding (not the
            // onboarding input scalar above, which is never the balance).
            'opening_payable_posted' => $this->whenHas('ledger_opening_payable', fn () => round((float) $this->ledger_opening_payable, 2)),
        ];
    }

    /**
     * Opening balance with sign applied: credit is payable to the supplier (+),
     * debit is receivable / prepaid (−).
     */
    private function openingBalanceSigned(): float
    {
        $amount = round((float) $this->opening_balance_amount, 2);

        return ($this->opening_balance_type ?? 'credit') === 'debit' ? -$amount : $amount;
    }
}
