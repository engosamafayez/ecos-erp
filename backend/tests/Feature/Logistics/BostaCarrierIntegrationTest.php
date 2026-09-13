<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Logistics\Carriers\Application\Actions\CreateExternalCarrierShipmentAction;
use Modules\Logistics\Carriers\Application\Services\ApplyCarrierDeliveryOutcomeService;
use Modules\Logistics\Carriers\Domain\Exceptions\CarrierException;
use Modules\Logistics\Carriers\Domain\Models\CarrierAccount;
use Modules\Logistics\Carriers\Domain\Models\CarrierShipment;
use Modules\Logistics\Carriers\Domain\Models\CarrierStatusMapping;
use Modules\Logistics\Carriers\Domain\Services\CarrierAdapterFactory;
use Modules\Logistics\Carriers\Infrastructure\Adapters\Bosta\BostaCarrierAdapter;
use Modules\Logistics\Carriers\Infrastructure\Adapters\InternalFleetAdapter;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripType;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\ShippingCompanies\Domain\Models\ShippingCompany;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-OPS-03-TASK1-BOSTA — source-level test coverage for the
 * Bosta external-carrier integration. Written for a LATER NEXT execution
 * pass (this task's own instructions: no PHPUnit/DB/migrate run in this
 * pass) — not executed here. Fixtures follow this codebase's established
 * conventions (DatabaseTransactions, real permission grants via
 * userWithGrants-style helpers, Http::fake() for the one real outbound HTTP
 * boundary — never a live Bosta call).
 *
 * Covers OPS-03-TASK1 §29 items 1-17.
 */
final class BostaCarrierIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private ShippingCompany $bostaShippingCompany;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.bosta.api_key' => 'test-key', 'services.bosta.base_url' => 'https://example.test/api/v2']);

        $this->company = Company::factory()->create();
        $this->bostaShippingCompany = ShippingCompany::create([
            'name' => 'Bosta', 'code' => 'BOSTA-'.substr(uniqid(), -6), 'type' => ShippingCompany::TYPE_EXTERNAL,
            'status' => ShippingCompany::STATUS_ACTIVE,
        ]);
    }

    private function activeAccount(?Company $company = null): CarrierAccount
    {
        return CarrierAccount::create([
            'company_id' => ($company ?? $this->company)->id,
            'shipping_company_id' => $this->bostaShippingCompany->id,
            'adapter_key' => BostaCarrierAdapter::KEY,
            'code' => 'BOSTA-'.substr(uniqid(), -6),
            'name' => 'Bosta Main',
            'mode' => CarrierAccount::MODE_EXTERNAL,
            'status' => CarrierAccount::STATUS_ACTIVE,
        ]);
    }

    /** @return array{trip: Trip, stop: DeliveryStop} */
    private function externalCarrierTripWithStop(?CarrierAccount $account = null): array
    {
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId, 'code' => 'CUS-'.substr(md5($customerId), 0, 8),
            'name' => 'Bosta Test Customer', 'phone' => '01000000000',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId, 'company_id' => $this->company->id, 'customer_id' => $customerId,
            'order_number' => 'ORD-'.substr(md5($orderId), 0, 8), 'order_date' => now()->toDateString(),
            'shipping_address' => '123 Test St, Cairo', 'total' => 500, 'deposit_amount' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $trip = Trip::create([
            'company_id' => $this->company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Bosta Test Trip',
            'type' => TripType::ExternalCarrier->value,
            'status' => TripStatus::cases()[0]->value,
            'capacity' => 10,
            'shipping_company_id' => $this->bostaShippingCompany->id,
        ]);

        $stop = DeliveryStop::create([
            'uuid' => (string) Str::uuid(),
            'trip_id' => $trip->id,
            'order_id' => $orderId,
            'sequence' => 1,
            'status' => DeliveryStopStatus::Pending->value,
        ]);

        return ['trip' => $trip, 'stop' => $stop];
    }

    // ── 1. Adapter resolution ─────────────────────────────────────────────────

    public function test_bosta_adapter_resolves_through_the_existing_carrier_account(): void
    {
        $account = $this->activeAccount();

        $adapter = app(CarrierAdapterFactory::class)->for($account);

        self::assertInstanceOf(BostaCarrierAdapter::class, $adapter);
    }

    // ── 2/3. Company + status eligibility ────────────────────────────────────

    public function test_a_foreign_companys_bosta_account_is_never_eligible(): void
    {
        $otherCompany = Company::factory()->create();
        $this->activeAccount($otherCompany); // only a FOREIGN account exists
        ['stop' => $stop] = $this->externalCarrierTripWithStop();

        $this->expectException(CarrierException::class);
        app(CreateExternalCarrierShipmentAction::class)->execute($stop);
    }

    public function test_a_disabled_bosta_account_is_never_eligible(): void
    {
        $account = $this->activeAccount();
        $account->update(['status' => CarrierAccount::STATUS_DISABLED]);
        ['stop' => $stop] = $this->externalCarrierTripWithStop();

        $this->expectException(CarrierException::class);
        app(CreateExternalCarrierShipmentAction::class)->execute($stop);
    }

    // ── 4/5. Shipment creation + idempotency ─────────────────────────────────

    public function test_external_shipment_creation_persists_the_bosta_reference(): void
    {
        $this->activeAccount();
        ['stop' => $stop] = $this->externalCarrierTripWithStop();

        Http::fake(['*' => Http::response(['_id' => 'bosta-123', 'trackingNumber' => 'TRK-999', 'state' => 'Created'], 201)]);

        $shipment = app(CreateExternalCarrierShipmentAction::class)->execute($stop);

        self::assertSame('bosta-123', $shipment->external_reference);
        self::assertSame('TRK-999', $shipment->tracking_number);
    }

    public function test_retrying_shipment_creation_does_not_create_a_duplicate_carrier_shipment(): void
    {
        $this->activeAccount();
        ['stop' => $stop] = $this->externalCarrierTripWithStop();

        Http::fake(['*' => Http::response(['_id' => 'bosta-123'], 201)]);

        $first = app(CreateExternalCarrierShipmentAction::class)->execute($stop);
        $second = app(CreateExternalCarrierShipmentAction::class)->execute($stop);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, CarrierShipment::where('delivery_stop_id', $stop->id)->count());
        Http::assertSentCount(1); // the adapter was never called a second time
    }

    // ── 6/7. Status mapping ───────────────────────────────────────────────────

    public function test_a_mapped_bosta_status_normalizes_through_carrier_status_mapping(): void
    {
        $account = $this->activeAccount();
        CarrierStatusMapping::create([
            'carrier_account_id' => $account->id, 'carrier_status' => 'Delivered', 'delivery_status' => 'delivered',
        ]);

        $event = (new BostaCarrierAdapter)->parseWebhook($account, ['state' => 'Delivered', '_id' => 'trk-1']);

        self::assertSame('delivered', $event->metadata['live_delivery_stop_status']);
    }

    public function test_an_unmapped_bosta_status_does_not_mutate_canonical_delivery_state(): void
    {
        $account = $this->activeAccount();
        ['stop' => $stop] = $this->externalCarrierTripWithStop();
        $shipment = CarrierShipment::create([
            'company_id' => $this->company->id, 'trip_id' => $stop->trip_id, 'delivery_stop_id' => $stop->id,
            'carrier_account_id' => $account->id, 'external_reference' => 'bosta-123',
        ]);

        // No CarrierStatusMapping row exists for "Some New Status".
        $event = (new BostaCarrierAdapter)->parseWebhook($account, ['state' => 'Some New Status', '_id' => 'trk-1']);
        app(ApplyCarrierDeliveryOutcomeService::class)->apply($shipment, $event);

        self::assertSame(DeliveryStopStatus::Pending->value, $stop->refresh()->status);
    }

    // ── 8/9. Webhook idempotency + canonical transition ──────────────────────

    public function test_duplicate_webhook_event_has_one_canonical_effect(): void
    {
        $account = $this->activeAccount();
        CarrierStatusMapping::create([
            'carrier_account_id' => $account->id, 'carrier_status' => 'Delivered', 'delivery_status' => 'delivered',
        ]);
        ['stop' => $stop] = $this->externalCarrierTripWithStop();
        $shipment = CarrierShipment::create([
            'company_id' => $this->company->id, 'trip_id' => $stop->trip_id, 'delivery_stop_id' => $stop->id,
            'carrier_account_id' => $account->id, 'external_reference' => 'bosta-123', 'tracking_number' => 'bosta-123',
        ]);

        $adapter = new BostaCarrierAdapter;
        $event = $adapter->parseWebhook($account, ['state' => 'Delivered', '_id' => 'trk-1', 'eventId' => 'evt-1']);

        $first = app(ApplyCarrierDeliveryOutcomeService::class)->apply($shipment, $event);
        $second = app(ApplyCarrierDeliveryOutcomeService::class)->apply($shipment, $event);

        self::assertTrue($first['applied']);
        self::assertFalse($second['applied']); // already settled — a no-op, not a regression
        self::assertSame('delivered', $stop->refresh()->status->value);
    }

    public function test_a_delivered_event_uses_the_canonical_delivery_transition(): void
    {
        $account = $this->activeAccount();
        CarrierStatusMapping::create([
            'carrier_account_id' => $account->id, 'carrier_status' => 'Delivered', 'delivery_status' => 'delivered',
        ]);
        ['stop' => $stop] = $this->externalCarrierTripWithStop();
        $shipment = CarrierShipment::create([
            'company_id' => $this->company->id, 'trip_id' => $stop->trip_id, 'delivery_stop_id' => $stop->id,
            'carrier_account_id' => $account->id, 'external_reference' => 'bosta-123',
        ]);

        $event = (new BostaCarrierAdapter)->parseWebhook($account, ['state' => 'Delivered', '_id' => 'trk-1']);
        $result = app(ApplyCarrierDeliveryOutcomeService::class)->apply($shipment, $event);

        self::assertTrue($result['applied']);
        self::assertSame(DeliveryStopStatus::Delivered, $stop->refresh()->status);
        self::assertNotNull($stop->completed_at);
    }

    // ── 10. Driver-independent ────────────────────────────────────────────────

    public function test_external_carrier_outcome_requires_no_driver_identity(): void
    {
        ['trip' => $trip] = $this->externalCarrierTripWithStop();

        self::assertSame(0, DB::table('logistics_drivers')->where('company_id', $this->company->id)->count());
        self::assertNull($trip->driverVehicleAssignment);
    }

    // ── 11. Returns never restore Inventory ──────────────────────────────────

    public function test_a_carrier_return_outcome_does_not_restore_inventory(): void
    {
        $account = $this->activeAccount();
        CarrierStatusMapping::create([
            'carrier_account_id' => $account->id, 'carrier_status' => 'Returned', 'delivery_status' => 'returned',
        ]);
        ['stop' => $stop] = $this->externalCarrierTripWithStop();
        $shipment = CarrierShipment::create([
            'company_id' => $this->company->id, 'trip_id' => $stop->trip_id, 'delivery_stop_id' => $stop->id,
            'carrier_account_id' => $account->id, 'external_reference' => 'bosta-123',
        ]);
        $before = VehicleInventoryItem::where('company_id', $this->company->id)->count();

        $event = (new BostaCarrierAdapter)->parseWebhook($account, ['state' => 'Returned', '_id' => 'trk-1']);
        app(ApplyCarrierDeliveryOutcomeService::class)->apply($shipment, $event);

        self::assertSame(DeliveryStopStatus::Returned, $stop->refresh()->status);
        self::assertSame($before, VehicleInventoryItem::where('company_id', $this->company->id)->count());
    }

    // ── 12. Cancellation honesty ──────────────────────────────────────────────

    public function test_bosta_cancellation_is_reported_as_unsupported_rather_than_faked(): void
    {
        $adapter = new BostaCarrierAdapter;

        self::assertFalse(
            method_exists($adapter, 'cancelShipment'),
            'Bosta has no verified cancel endpoint (Section 3) — no cancelShipment() method must exist to fake one.',
        );
    }

    // ── 13. Carrier cost — not available ─────────────────────────────────────

    public function test_no_carrier_cost_or_insurance_column_exists_without_a_verified_source(): void
    {
        self::assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('carrier_shipments', 'carrier_cost_amount'),
            'no verified Bosta response field for carrier payable cost exists — no column must be invented.',
        );
        self::assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('carrier_shipments', 'insurance_amount'));
    }

    // ── 14. Customer charge stays separate ───────────────────────────────────

    public function test_shipment_creation_never_touches_customer_shipping_price_authorities(): void
    {
        $this->activeAccount();
        ['stop' => $stop] = $this->externalCarrierTripWithStop();
        Http::fake(['*' => Http::response(['_id' => 'bosta-123'], 201)]);

        $before = DB::table('config_delivery_geographies')->count() + DB::table('config_brand_shipping_rules')->count();
        app(CreateExternalCarrierShipmentAction::class)->execute($stop);
        $after = DB::table('config_delivery_geographies')->count() + DB::table('config_brand_shipping_rules')->count();

        self::assertSame($before, $after, 'carrier tendering must never write customer-facing shipping price tables.');
    }

    // ── 15. Daily Transfer Cost absent ────────────────────────────────────────

    public function test_daily_transfer_cost_has_no_implementation(): void
    {
        self::assertFalse(
            \Illuminate\Support\Facades\Schema::hasTable('daily_transfer_costs'),
            'OPS-03 §22: Daily Transfer Cost is deferred pending business definition — no table, field, or formula.',
        );
    }

    // ── 16. No automatic Supplier creation ───────────────────────────────────

    public function test_creating_a_carrier_account_never_creates_a_supplier(): void
    {
        $before = Supplier::count();
        $this->activeAccount();

        self::assertSame($before, Supplier::count());
    }

    // ── 17. Internal Fleet path unchanged ────────────────────────────────────

    public function test_internal_fleet_adapter_behaviour_is_unchanged(): void
    {
        $adapter = new InternalFleetAdapter;
        $account = new CarrierAccount(['mode' => CarrierAccount::MODE_INTERNAL]);

        $caps = $adapter->capabilities($account);

        self::assertTrue($caps->supports(\Modules\Logistics\Carriers\Domain\ValueObjects\CarrierCapabilitySet::TRACKING));
        self::assertFalse($caps->supports(\Modules\Logistics\Carriers\Domain\ValueObjects\CarrierCapabilitySet::RATING));
        self::assertTrue($adapter->testConnection($account)['ok']);
        self::assertNotInstanceOf(\Modules\Logistics\Carriers\Domain\Contracts\TenderingCarrierAdapterInterface::class, $adapter);
    }
}
