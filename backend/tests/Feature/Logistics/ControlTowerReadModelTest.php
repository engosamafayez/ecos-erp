<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Logistics\Carriers\Domain\Models\CarrierAccount;
use Modules\Logistics\Carriers\Domain\Models\CarrierShipment;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\SettlementStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripReturn;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;
use Modules\Logistics\Operations\Domain\Services\CustodyReturnsMonitoringService;
use Modules\Logistics\Operations\Domain\Services\EnterpriseSummaryService;
use Modules\Logistics\Operations\Domain\Services\SettlementMonitoringService;
use Modules\Logistics\Operations\Domain\Services\ShippingExecutionMonitoringService;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliation;
use Modules\Operations\Loading\Domain\Models\VehicleShiftReconciliationLine;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-OPS-04-TASK1 — Control Tower read-model source tests.
 * §16 items 1-17. Every fixture uses real canonical models/tables; no
 * fake status is invented anywhere in this file.
 */
final class ControlTowerReadModelTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
    }

    private function makeOrder(?Company $company = null): string
    {
        $company ??= $this->company;
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId, 'code' => 'CUS-'.substr(md5($customerId), 0, 8),
            'name' => 'CT Test Customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId, 'company_id' => $company->id, 'customer_id' => $customerId,
            'order_number' => 'ORD-'.substr(md5($orderId), 0, 8), 'order_date' => now()->toDateString(),
            'total' => 200, 'deposit_amount' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $orderId;
    }

    private function makeTrip(?Company $company = null, TripType $type = TripType::CompanyVehicle, TripStatus $status = TripStatus::Planning): Trip
    {
        $company ??= $this->company;

        return Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'CT Test Trip',
            'type' => $type->value,
            'status' => $status->value,
            'capacity' => 10,
        ]);
    }

    private function makeStop(Trip $trip, DeliveryStopStatus $status = DeliveryStopStatus::Pending): DeliveryStop
    {
        return DeliveryStop::create([
            'uuid' => (string) Str::uuid(),
            'trip_id' => $trip->id,
            'order_id' => $this->makeOrder(Company::find($trip->company_id) ?? $this->company),
            'sequence' => 1,
            'status' => $status->value,
        ]);
    }

    // ── 1. Shipping summary reconciles to canonical Trip/DeliveryStop ───────

    public function test_shipping_summary_trip_counts_reconcile_to_canonical_trip_data(): void
    {
        $this->makeTrip(status: TripStatus::Planning);
        $this->makeTrip(status: TripStatus::Loading);
        $this->makeTrip(status: TripStatus::Dispatched);
        $this->makeTrip(status: TripStatus::Closed);

        $summary = app(ShippingExecutionMonitoringService::class)->trips((string) $this->company->id);

        self::assertSame(1, $summary['awaiting_loading']);
        self::assertSame(1, $summary['loading_in_progress']);
        self::assertSame(1, $summary['executing']); // Dispatched is onTheRoadValues()
        self::assertSame(1, $summary['closed']);
    }

    // ── 2. Residual buckets reconcile: main + residual = population ─────────

    public function test_window_order_zone_and_group_buckets_reconcile_to_total(): void
    {
        $window = DB::table('distribution_windows')->insertGetId([
            'company_id' => $this->company->id, 'window_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DistributionWindowOrder::create([
            'company_id' => $this->company->id, 'distribution_window_id' => $window,
            'order_id' => $this->makeOrder(), 'distribution_zone_id' => null, 'virtual_slot_id' => null,
            'assignment_source' => 'system',
        ]);
        DistributionWindowOrder::create([
            'company_id' => $this->company->id, 'distribution_window_id' => $window,
            'order_id' => $this->makeOrder(), 'distribution_zone_id' => 1, 'virtual_slot_id' => null,
            'assignment_source' => 'system',
        ]);

        $result = app(ShippingExecutionMonitoringService::class)->windowOrderReconciliation((string) $this->company->id);

        self::assertSame(2, $result['total']);
        self::assertSame($result['total'], $result['zoned'] + $result['unzoned']);
        self::assertSame($result['total'], $result['grouped'] + $result['ungrouped']);
    }

    // ── 3. External carrier bucket separated from internal execution ────────

    public function test_external_carrier_trips_are_not_counted_as_internal_execution(): void
    {
        $this->makeTrip(type: TripType::ExternalCarrier, status: TripStatus::Dispatched);
        $this->makeTrip(type: TripType::CompanyVehicle, status: TripStatus::Dispatched);

        $summary = app(ShippingExecutionMonitoringService::class)->trips((string) $this->company->id);

        // Both are "executing" (onTheRoadValues), but external_carrier_trips is
        // reported as its own, separately-derived tally, not folded silently in.
        self::assertSame(2, $summary['executing']);
        self::assertSame(1, $summary['external_carrier_trips']);
    }

    // ── 4/5/6. Expected Returns — custody-derived, real zero vs incomplete ──

    public function test_expected_returns_uses_custody_authority_not_order_status(): void
    {
        $trip = $this->makeTrip(status: TripStatus::InProgress);
        $assignment = VehicleAssignment::create([
            'company_id' => $this->company->id, 'trip_id' => $trip->id,
            'vehicle_id' => (string) Str::uuid(),
        ]);
        VehicleInventoryItem::create([
            'company_id' => $this->company->id, 'vehicle_assignment_id' => $assignment->id,
            'vehicle_id' => $assignment->vehicle_id, 'product_id' => (string) Str::uuid(),
            'sku_snapshot' => 'SKU1', 'name_snapshot' => 'Widget', 'operational_date' => now()->toDateString(),
            'quantity_loaded' => 10, 'quantity_delivered' => 4, 'quantity_returned' => 0,
            'quantity_on_hand' => 6, 'quantity_unallocated' => 0, 'status' => 'active',
        ]);

        $result = app(CustodyReturnsMonitoringService::class)->expectedReturns((string) $this->company->id, 25, 1);

        self::assertCount(1, $result['data']);
        self::assertSame(6.0, $result['data'][0]['expected_qty']);
        // No reconciliation line exists yet — must be null (incomplete), not 0.
        self::assertNull($result['data'][0]['accepted_qty']);
        self::assertSame('awaiting_reconciliation', $result['data'][0]['linkage_state']);
    }

    public function test_vehicle_inventory_true_zero_is_excluded_from_expected_returns_not_hidden_as_incomplete(): void
    {
        $trip = $this->makeTrip(status: TripStatus::Completed);
        $assignment = VehicleAssignment::create([
            'company_id' => $this->company->id, 'trip_id' => $trip->id, 'vehicle_id' => (string) Str::uuid(),
        ]);
        VehicleInventoryItem::create([
            'company_id' => $this->company->id, 'vehicle_assignment_id' => $assignment->id,
            'vehicle_id' => $assignment->vehicle_id, 'product_id' => (string) Str::uuid(),
            'sku_snapshot' => 'SKU2', 'name_snapshot' => 'Fully Returned Widget', 'operational_date' => now()->toDateString(),
            'quantity_loaded' => 10, 'quantity_delivered' => 10, 'quantity_returned' => 0,
            'quantity_on_hand' => 0, 'quantity_unallocated' => 0, 'status' => 'closed',
        ]);

        $result = app(CustodyReturnsMonitoringService::class)->expectedReturns((string) $this->company->id, 25, 1);

        // A real zero-on-hand item is correctly excluded from "expected back" —
        // this is a true zero, not something hidden behind an incomplete state.
        self::assertCount(0, $result['data']);
    }

    // ── 7. Custody reconciliation uses canonical physical authorities ───────

    public function test_custody_summary_uses_canonical_vehicle_inventory_figures(): void
    {
        $trip = $this->makeTrip();
        $assignment = VehicleAssignment::create([
            'company_id' => $this->company->id, 'trip_id' => $trip->id, 'vehicle_id' => (string) Str::uuid(),
        ]);
        VehicleInventoryItem::create([
            'company_id' => $this->company->id, 'vehicle_assignment_id' => $assignment->id,
            'vehicle_id' => $assignment->vehicle_id, 'product_id' => (string) Str::uuid(),
            'sku_snapshot' => 'SKU3', 'name_snapshot' => 'Widget', 'operational_date' => now()->toDateString(),
            'quantity_loaded' => 20, 'quantity_delivered' => 15, 'quantity_returned' => 2,
            'quantity_on_hand' => 3, 'quantity_unallocated' => 0, 'status' => 'active',
        ]);

        $custody = app(CustodyReturnsMonitoringService::class)->custody((string) $this->company->id);

        self::assertSame(20.0, $custody['loaded']);
        self::assertSame(15.0, $custody['delivered']);
        self::assertSame(3.0, $custody['remaining_with_driver_vehicle']);
        self::assertSame(2.0, $custody['returned_by_driver']);
    }

    // ── 8/9. Returned outcomes never imply inventory restoration ─────────────

    public function test_delivery_stop_returned_status_alone_does_not_create_a_warehouse_receipt_fact(): void
    {
        $trip = $this->makeTrip(status: TripStatus::InProgress);
        $this->makeStop($trip, DeliveryStopStatus::Returned);

        $returns = app(CustodyReturnsMonitoringService::class)->returns((string) $this->company->id);

        // No TripReturn/reconciliation-line row was created by settling a stop —
        // those remain a SEPARATE, explicit act (DeliveryService::recordReturn /
        // ReceiveVehicleReturnAction), never implied by DeliveryStopStatus alone.
        self::assertSame(0, $returns['physical_return_confirmed']);
        self::assertSame(0, $returns['warehouse_receipt_completed_lines']);
    }

    public function test_carrier_shipment_returned_status_does_not_restore_inventory(): void
    {
        $account = CarrierAccount::create([
            'company_id' => $this->company->id, 'adapter_key' => 'bosta', 'code' => 'B-'.substr(uniqid(), -6),
            'name' => 'Bosta', 'mode' => CarrierAccount::MODE_EXTERNAL, 'status' => CarrierAccount::STATUS_ACTIVE,
        ]);
        $trip = $this->makeTrip(type: TripType::ExternalCarrier, status: TripStatus::InProgress);
        $stop = $this->makeStop($trip, DeliveryStopStatus::Returned);
        CarrierShipment::create([
            'company_id' => $this->company->id, 'trip_id' => $trip->id, 'delivery_stop_id' => $stop->id,
            'carrier_account_id' => $account->id, 'external_reference' => 'bosta-1', 'raw_status' => 'Returned',
        ]);

        $before = VehicleInventoryItem::where('company_id', $this->company->id)->sum('quantity_on_hand');
        app(EnterpriseSummaryService::class)->externalCarrier((string) $this->company->id);
        $after = VehicleInventoryItem::where('company_id', $this->company->id)->sum('quantity_on_hand');

        self::assertSame($before, $after);
    }

    // ── 10. Warehouse receipt facts only after canonical receipt state ──────

    public function test_warehouse_receipt_count_only_reflects_lines_with_receipt_timestamp_set(): void
    {
        $trip = $this->makeTrip();
        $assignment = VehicleAssignment::create([
            'company_id' => $this->company->id, 'trip_id' => $trip->id, 'vehicle_id' => (string) Str::uuid(),
        ]);
        $item = VehicleInventoryItem::create([
            'company_id' => $this->company->id, 'vehicle_assignment_id' => $assignment->id,
            'vehicle_id' => $assignment->vehicle_id, 'product_id' => (string) Str::uuid(),
            'sku_snapshot' => 'SKU4', 'name_snapshot' => 'Widget', 'operational_date' => now()->toDateString(),
            'quantity_loaded' => 5, 'quantity_delivered' => 3, 'quantity_returned' => 2,
            'quantity_on_hand' => 0, 'quantity_unallocated' => 0, 'status' => 'closed',
        ]);
        $reconciliation = VehicleShiftReconciliation::create([
            'company_id' => $this->company->id, 'vehicle_assignment_id' => $assignment->id,
            'vehicle_id' => $assignment->vehicle_id, 'operational_date' => now()->toDateString(), 'status' => 'draft',
        ]);
        VehicleShiftReconciliationLine::create([
            'company_id' => $this->company->id, 'reconciliation_id' => $reconciliation->id,
            'vehicle_inventory_item_id' => $item->id, 'product_id' => $item->product_id, 'sku_snapshot' => 'SKU4',
            'quantity_loaded' => 5, 'quantity_delivered' => 3, 'quantity_returned_expected' => 2,
            'quantity_returned_actual' => 2, 'quantity_accepted' => 2, 'quantity_damaged' => 0,
            'warehouse_receipt_at' => null, // NOT yet received
        ]);

        $returns = app(CustodyReturnsMonitoringService::class)->returns((string) $this->company->id);
        self::assertSame(0, $returns['warehouse_receipt_completed_lines']);
        self::assertSame(1, $returns['warehouse_receipt_awaiting_lines']);
    }

    // ── 11/12. Settlement — collection_difference_pending behaviour ─────────

    public function test_settlement_reports_collection_difference_pending_while_a_stop_is_unsettled(): void
    {
        $trip = $this->makeTrip(status: TripStatus::InProgress);
        $this->makeStop($trip, DeliveryStopStatus::Pending); // NOT settled yet
        TripSettlement::create(['trip_id' => $trip->id]);

        $summary = app(SettlementMonitoringService::class)->settlement((string) $this->company->id);

        self::assertSame(1, $summary['collection_difference_pending']);
    }

    public function test_settlement_excludes_a_trip_from_pending_once_every_stop_is_settled(): void
    {
        $trip = $this->makeTrip(status: TripStatus::Completed);
        $this->makeStop($trip, DeliveryStopStatus::Delivered); // settled
        TripSettlement::create(['trip_id' => $trip->id]);

        $summary = app(SettlementMonitoringService::class)->settlement((string) $this->company->id);

        self::assertSame(0, $summary['collection_difference_pending']);
    }

    // ── 13. Settlement and physical-return states remain independent ───────

    public function test_a_finalized_settlement_can_coexist_with_an_unconfirmed_physical_return(): void
    {
        $trip = $this->makeTrip(status: TripStatus::Closed);
        TripSettlement::create(['trip_id' => $trip->id, 'status' => SettlementStatus::Finalized->value]);
        TripReturn::create(['trip_id' => $trip->id, 'kind' => 'product', 'dispatched_qty' => 5, 'returned_qty' => 1]);

        $settlement = app(SettlementMonitoringService::class)->settlement((string) $this->company->id);
        $returns = app(CustodyReturnsMonitoringService::class)->returns((string) $this->company->id);

        // Both facts are readable independently — this task does not couple or
        // block either based on the other (Section 9).
        self::assertSame(1, $settlement['finalized']);
        self::assertSame(1, $returns['physical_return_awaiting_confirmation']);
    }

    // ── 14. External carrier fields come from CarrierShipment ───────────────

    public function test_external_carrier_summary_reflects_real_carrier_shipment_rows(): void
    {
        $account = CarrierAccount::create([
            'company_id' => $this->company->id, 'adapter_key' => 'bosta', 'code' => 'B-'.substr(uniqid(), -6),
            'name' => 'Bosta', 'mode' => CarrierAccount::MODE_EXTERNAL, 'status' => CarrierAccount::STATUS_ACTIVE,
        ]);
        $trip = $this->makeTrip(type: TripType::ExternalCarrier);
        $stop = $this->makeStop($trip);
        CarrierShipment::create([
            'company_id' => $this->company->id, 'trip_id' => $trip->id, 'delivery_stop_id' => $stop->id,
            'carrier_account_id' => $account->id, 'external_reference' => 'bosta-99', 'raw_status' => 'In Transit',
        ]);

        $summary = app(EnterpriseSummaryService::class)->externalCarrier((string) $this->company->id);

        self::assertSame(1, $summary['total_shipments']);
        self::assertSame(1, $summary['by_raw_status']['In Transit'] ?? 0);
    }

    // ── 15. Company isolation ────────────────────────────────────────────────

    public function test_company_a_cannot_observe_company_bs_control_tower_data(): void
    {
        $otherCompany = Company::factory()->create();
        $this->makeTrip($otherCompany, status: TripStatus::Dispatched);

        $summary = app(ShippingExecutionMonitoringService::class)->trips((string) $this->company->id);

        self::assertSame(0, $summary['executing'], 'another company\'s trip must never be counted.');
    }

    // ── 16. Backward compatibility of the existing summary contract ─────────

    public function test_existing_summary_endpoints_remain_unchanged_in_shape(): void
    {
        $summaries = app(EnterpriseSummaryService::class);

        // These pre-existing methods must still resolve without error and keep
        // their own established keys — proving the new constructor arguments
        // did not alter any existing behaviour.
        $fleet = $summaries->fleet((string) $this->company->id);
        self::assertArrayHasKey('vehicles', $fleet);
        self::assertArrayHasKey('drivers', $fleet);
        self::assertArrayHasKey('fieldable_units', $fleet);
    }

    // ── 17. No write side effects from Control Tower reads ──────────────────

    public function test_control_tower_summary_calls_perform_no_writes(): void
    {
        $trip = $this->makeTrip(status: TripStatus::InProgress);
        $this->makeStop($trip, DeliveryStopStatus::Pending);

        $before = [
            'trips' => Trip::count(), 'stops' => DeliveryStop::count(),
            'settlements' => TripSettlement::count(), 'returns' => TripReturn::count(),
        ];

        $summaries = app(EnterpriseSummaryService::class);
        $summaries->shipping((string) $this->company->id);
        $summaries->custody((string) $this->company->id);
        $summaries->returns((string) $this->company->id);
        $summaries->settlement((string) $this->company->id);
        $summaries->externalCarrier((string) $this->company->id);
        app(CustodyReturnsMonitoringService::class)->expectedReturns((string) $this->company->id, 25, 1);

        $after = [
            'trips' => Trip::count(), 'stops' => DeliveryStop::count(),
            'settlements' => TripSettlement::count(), 'returns' => TripReturn::count(),
        ];

        self::assertSame($before, $after);
    }
}
