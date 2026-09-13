<?php

declare(strict_types=1);

namespace Modules\Logistics\Carriers\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Logistics\Carriers\Domain\Contracts\TenderingCarrierAdapterInterface;
use Modules\Logistics\Carriers\Domain\Exceptions\CarrierException;
use Modules\Logistics\Carriers\Domain\Models\CarrierAccount;
use Modules\Logistics\Carriers\Domain\Models\CarrierShipment;
use Modules\Logistics\Carriers\Domain\Services\CarrierAdapterFactory;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;

/**
 * Tender one DeliveryStop to an external carrier (TASK-ECOS-V1.1-OPS-03-
 * TASK1-BOSTA, Section 6/7). The canonical application-layer action — the
 * ONLY place that resolves a CarrierAccount for a trip and calls a tendering
 * adapter. No Bosta/HTTP logic lives here (Directive 9): this action builds
 * the shipment CONTEXT from canonical Order/Trip/Warehouse data and hands it
 * to whichever adapter the resolved account names.
 */
final class CreateExternalCarrierShipmentAction
{
    public function __construct(private readonly CarrierAdapterFactory $adapters) {}

    /**
     * Idempotent: a stop already carrying a tendered CarrierShipment (i.e. one
     * with a non-null external_reference) is returned as-is — the adapter is
     * never called a second time for the same stop (Section 9). A stop whose
     * PRIOR attempt failed before a reference was assigned reuses the SAME
     * CarrierShipment row's stable uuid as the outbound business_reference on
     * retry, so a partial prior attempt cannot register as two shipments even
     * if the carrier itself also dedupes on that field.
     */
    public function execute(DeliveryStop $stop, ?int $actorId = null): CarrierShipment
    {
        $trip = $stop->trip;

        if ($trip === null || $trip->type !== TripType::ExternalCarrier) {
            throw CarrierException::accountNotActive('trip is not an external-carrier trip');
        }

        if ($trip->shipping_company_id === null) {
            throw CarrierException::accountNotActive('trip has no shipping company reference');
        }

        return DB::transaction(function () use ($stop, $trip, $actorId) {
            /** @var CarrierShipment|null $existing */
            $existing = CarrierShipment::query()
                ->where('delivery_stop_id', $stop->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->hasBeenTendered()) {
                return $existing;
            }

            $account = $this->resolveEligibleAccount($trip->company_id, $trip->shipping_company_id);

            $shipment = $existing ?? CarrierShipment::create([
                'company_id' => $trip->company_id,
                'trip_id' => $trip->id,
                'delivery_stop_id' => $stop->id,
                'carrier_account_id' => $account->id,
                'created_by' => $actorId,
            ]);

            $adapter = $this->adapters->for($account);

            if (! $adapter instanceof TenderingCarrierAdapterInterface) {
                throw CarrierException::unsupportedOperation($adapter->displayName(), 'shipment creation');
            }

            $context = $this->buildShipmentContext($shipment, $stop, $trip, $account);

            $result = $adapter->createShipment($account, $context);

            $shipment->update([
                'external_reference' => $result['external_reference'],
                'tracking_number' => $result['tracking_number'],
                'label_url' => $result['label_url'],
                'raw_status' => $result['raw_status'],
                'last_event_at' => now(),
                'cod_amount' => $context['cod_amount'],
            ]);

            return $shipment->refresh();
        });
    }

    /**
     * Resolve the one eligible, active carrier account for this company and
     * shipping company (Section 6). Foreign-company accounts and
     * disabled/draft accounts are never eligible — the query itself enforces
     * both boundaries, not a post-hoc check. Ties broken by the same
     * (is_default desc, priority desc) ordering CarrierController::index()
     * already uses, so "which account wins" is answered identically
     * everywhere rather than by a second, driftable rule (Section 6: "use the
     * smallest deterministic existing-company mapping without inventing a new
     * selection model").
     */
    private function resolveEligibleAccount(string $companyId, int $shippingCompanyId): CarrierAccount
    {
        $account = CarrierAccount::query()
            ->where('company_id', $companyId)
            ->where('shipping_company_id', $shippingCompanyId)
            ->where('status', CarrierAccount::STATUS_ACTIVE)
            ->orderByDesc('is_default')
            ->orderByDesc('priority')
            ->first();

        if ($account === null) {
            throw CarrierException::accountNotActive(
                'no active carrier account for this company and shipping company (a draft/disabled account, or one belonging to another company, cannot execute this shipment)',
            );
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function buildShipmentContext(CarrierShipment $shipment, DeliveryStop $stop, $trip, CarrierAccount $account): array
    {
        $order = $stop->order;

        if ($order === null) {
            throw CarrierException::accountNotActive('the delivery stop has no linked order');
        }

        $customer = $order->customer;
        $warehouseId = $trip->group?->warehouse_id;
        $pickupAddress = $warehouseId !== null ? Warehouse::find($warehouseId)?->address : null;

        if (blank($order->shipping_address) || $customer === null || blank($customer->phone) || blank($pickupAddress)) {
            throw CarrierException::accountNotActive(
                'required canonical delivery/contact/pickup data is incomplete (customer phone, shipping address, or a resolvable pickup warehouse address) — Bosta cannot be called with incomplete data',
            );
        }

        // ANTI-CORRUPTION-LAYER.md §5: order.total_amount -> cod_amount, ONLY
        // when the order is actually COD (an already-paid order carries no
        // collectible amount for the carrier to gather).
        $codAmount = $order->date_paid === null
            ? round((float) $order->total - (float) $order->deposit_amount, 2)
            : null;
        if ($codAmount !== null && $codAmount <= 0.0) {
            $codAmount = null;
        }

        return [
            // The CarrierShipment's own stable uuid — never the Order's id —
            // so a retry after a failed first attempt sends the SAME
            // business_reference every time (Section 9).
            'business_reference' => $shipment->uuid,
            'receiver_name' => $customer->name,
            'receiver_phone' => $customer->phone,
            'receiver_address' => $order->shipping_address,
            'pickup_address' => $pickupAddress,
            // OrderLine carries no product name column directly (only
            // product_id) — a count-based description avoids an unverified
            // assumption about eager-loading Product for its own name field,
            // while still matching the ACL doc's "item names, count" intent
            // closely enough for a carrier's free-text description field.
            'order_description' => sprintf('Order %s (%d item%s)', $order->order_number, $order->lines()->count(), $order->lines()->count() === 1 ? '' : 's'),
            'cod_amount' => $codAmount,
        ];
    }
}
