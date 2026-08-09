<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\InventoryItems\Domain\Enums\AvailabilityState;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Services\InventorySummaryService;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-PHASE3-STEP1-AVAILABILITY-STATE-001 — derived availability state.
 *
 * `availability_state` is a PROJECTION of the canonical `available` figure, not a
 * second calculation. These cases pin the projection and, equally importantly,
 * pin that the existing quantities are unchanged by its introduction.
 */
final class AvailabilityStateDerivationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    private function service(): InventorySummaryService
    {
        return app(InventorySummaryService::class);
    }

    private function stock(Product $product, float $onHand, float $reserved, ?Warehouse $warehouse = null): void
    {
        InventoryItem::query()->create([
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    // ── 1. Available ──────────────────────────────────────────────────────────

    public function test_positive_available_derives_in_stock(): void
    {
        $product = Product::factory()->create();
        $this->stock($product, onHand: 10, reserved: 4);

        $summary = $this->service()->summarize($product->id, $this->company->id);

        self::assertSame(6.0, $summary->available);
        self::assertSame(AvailabilityState::InStock, $summary->availabilityState);
    }

    // ── 2. Unavailable ────────────────────────────────────────────────────────

    public function test_fully_reserved_stock_derives_out_of_stock(): void
    {
        $product = Product::factory()->create();
        $this->stock($product, onHand: 5, reserved: 5);

        $summary = $this->service()->summarize($product->id, $this->company->id);

        self::assertSame(0.0, $summary->available);
        self::assertSame(AvailabilityState::OutOfStock, $summary->availabilityState);
    }

    // ── 3. Boundary conditions the existing rule actually supports ────────────

    public function test_zero_available_is_out_of_stock_not_in_stock(): void
    {
        $product = Product::factory()->create();
        $this->stock($product, onHand: 0, reserved: 0);

        self::assertSame(
            AvailabilityState::OutOfStock,
            $this->service()->summarize($product->id, $this->company->id)->availabilityState,
        );
    }

    /**
     * The canonical rule is clamp-per-warehouse THEN sum. An over-reserved
     * warehouse must not lend negative availability to a healthy one — under the
     * legacy sum-then-clamp shape this product would read 0 and be OutOfStock.
     */
    public function test_over_reserved_warehouse_does_not_drag_state_out_of_stock(): void
    {
        $product = Product::factory()->create();
        $second = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $this->stock($product, onHand: 2, reserved: 10);              // clamps to 0
        $this->stock($product, onHand: 7, reserved: 1, warehouse: $second); // 6

        $summary = $this->service()->summarize($product->id, $this->company->id);

        self::assertSame(6.0, $summary->available);
        self::assertSame(AvailabilityState::InStock, $summary->availabilityState);
    }

    // ── 4. Company scope ──────────────────────────────────────────────────────

    public function test_state_respects_company_scope(): void
    {
        $product = Product::factory()->create();
        $this->stock($product, onHand: 10, reserved: 0);

        $otherCompany = Company::factory()->create();

        // Scoped to a company holding none of this product's stock.
        $scoped = $this->service()->summarize($product->id, $otherCompany->id);
        self::assertSame(0.0, $scoped->available);
        self::assertSame(AvailabilityState::Untracked, $scoped->availabilityState);

        // Unscoped (null) aggregates across companies — the documented global view.
        $global = $this->service()->summarize($product->id, null);
        self::assertSame(10.0, $global->available);
        self::assertSame(AvailabilityState::InStock, $global->availabilityState);
    }

    // ── 5. Existing behaviour unchanged ───────────────────────────────────────

    public function test_existing_summary_fields_are_unchanged(): void
    {
        $product = Product::factory()->create();
        $this->stock($product, onHand: 9, reserved: 3);

        $summary = $this->service()->summarize($product->id, $this->company->id);
        $array = $summary->toArray();

        self::assertSame(9.0, $summary->onHand);
        self::assertSame(3.0, $summary->reserved);
        self::assertSame(6.0, $summary->available);
        self::assertSame(9.0, $array['total_on_hand']);
        self::assertSame(3.0, $array['total_reserved']);
        self::assertSame(6.0, $array['total_available']);
        self::assertCount(1, $summary->warehouses);

        // Additive only — the new key is present, nothing was removed or renamed.
        self::assertSame('in_stock', $array['availability_state']);
        foreach (['product_id', 'on_hand_qty', 'reserved_qty', 'available_qty', 'inventory_value', 'warehouses'] as $key) {
            self::assertArrayHasKey($key, $array);
        }
    }

    // ── 6. Missing source data ────────────────────────────────────────────────

    public function test_product_with_no_inventory_record_is_untracked(): void
    {
        $product = Product::factory()->create();

        $summary = $this->service()->summarize($product->id, $this->company->id);

        self::assertSame(0.0, $summary->available);
        self::assertSame(
            AvailabilityState::Untracked,
            $summary->availabilityState,
            'No inventory record is distinct from tracked-but-empty.',
        );
    }

    /** The DTO default keeps every pre-existing construction site valid. */
    public function test_dto_default_keeps_construction_additive(): void
    {
        $summary = new \Modules\Inventory\InventoryItems\Domain\DTO\InventorySummary(
            productId: 'p-1',
            onHand: 0.0,
            reserved: 0.0,
            available: 0.0,
            inventoryValue: 0.0,
            warehouses: [],
        );

        self::assertSame(AvailabilityState::Untracked, $summary->availabilityState);
    }
}
