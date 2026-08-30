<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\InventoryItems\Domain\Enums\AvailabilityState;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Enums\ProductAvailability;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\Products\Infrastructure\Repositories\EloquentProductRepository;
use Modules\Inventory\Products\Presentation\Http\Resources\ProductResource;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * T-1 — the approved BUSINESS product availability contract.
 *
 *   available >  0                              → in_stock
 *   available <= 0  AND  allow_negative = true  → negative_allowed
 *   available <= 0  AND  allow_negative = false → out_of_stock
 *
 * Three states. No fourth. A missing inventory row is classified by the same rule as a
 * tracked zero, and must never surface as `untracked` on a business surface.
 *
 * The data-platform contract (`AvailabilityState`, which DOES distinguish `Untracked`)
 * is asserted intact by P11 — the separation is the point of this task, so both halves
 * are proven, not just the new one.
 */
final class ProductAvailabilityContractTest extends TestCase
{
    use RefreshDatabase;

    /**
     * P12 needs a RESTRICTED actor: the baseline grant hands an unroled user the system
     * role, and an unrestricted actor is deliberately exempt from company scoping.
     */
    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function product(bool $allowNegative): Product
    {
        return Product::factory()->create([
            'company_id' => $this->company->id,
            'sku' => 'SKU-'.uniqid(),
            'allow_negative_stock' => $allowNegative,
        ]);
    }

    /** Give the product a tracked inventory row producing exactly $available. */
    private function stock(Product $p, float $onHand, float $reserved = 0.0): void
    {
        InventoryItem::query()->create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $p->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    /** The state the API actually projects, through the real resource. */
    private function apiState(Product $p): ?string
    {
        $row = (new EloquentProductRepository)
            ->paginate(['per_page' => 200])
            ->getCollection()
            ->firstWhere('id', $p->id);

        self::assertNotNull($row, 'Product missing from the repository projection.');

        return (new ProductResource($row))->toArray(request())['availability_state'];
    }

    /** The ids the availability FILTER returns for a state. */
    private function filtered(string $state): array
    {
        return (new EloquentProductRepository)
            ->paginate(['availability' => $state, 'per_page' => 200])
            ->getCollection()
            ->pluck('id')
            ->all();
    }

    // ── P1–P7 — the projection ────────────────────────────────────────────────

    /** P1 — positive available → In Stock. */
    public function test_p1_positive_available_is_in_stock(): void
    {
        $p = $this->product(allowNegative: false);
        $this->stock($p, 10.0);

        self::assertSame('in_stock', $this->apiState($p));
    }

    /** P2 — zero available + negative allowed → Negative Allowed. */
    public function test_p2_zero_available_with_negative_allowed(): void
    {
        $p = $this->product(allowNegative: true);
        $this->stock($p, 5.0, 5.0);   // available = 0

        self::assertSame('negative_allowed', $this->apiState($p));
    }

    /** P3 — zero available + negative NOT allowed → Out of Stock. */
    public function test_p3_zero_available_without_negative_allowed(): void
    {
        $p = $this->product(allowNegative: false);
        $this->stock($p, 5.0, 5.0);

        self::assertSame('out_of_stock', $this->apiState($p));
    }

    /** P4 — negative available + negative allowed → Negative Allowed. */
    public function test_p4_negative_available_with_negative_allowed(): void
    {
        $p = $this->product(allowNegative: true);
        $this->stock($p, 2.0, 7.0);   // available = -5

        self::assertSame('negative_allowed', $this->apiState($p));
    }

    /** P5 — negative available + negative NOT allowed → Out of Stock. */
    public function test_p5_negative_available_without_negative_allowed(): void
    {
        $p = $this->product(allowNegative: false);
        $this->stock($p, 2.0, 7.0);

        self::assertSame('out_of_stock', $this->apiState($p));
    }

    /**
     * P6 — NO inventory row + negative allowed → Negative Allowed.
     *
     * The heart of T-1. Before this change the resource projected `untracked` here while
     * the filter classified the same product as `negative_allowed`.
     */
    public function test_p6_missing_inventory_row_with_negative_allowed(): void
    {
        $p = $this->product(allowNegative: true);

        self::assertSame('negative_allowed', $this->apiState($p));
    }

    /** P7 — NO inventory row + negative NOT allowed → Out of Stock. */
    public function test_p7_missing_inventory_row_without_negative_allowed(): void
    {
        $p = $this->product(allowNegative: false);

        self::assertSame('out_of_stock', $this->apiState($p));
    }

    // ── P8/P9 — surface parity ────────────────────────────────────────────────

    /**
     * P8/P9 — Product table, Product drawer and Raw Materials all read the SAME field.
     *
     * Both surfaces are served by `/products` → `ProductResource`, and neither composes a
     * state client-side any more, so surface parity reduces to: the API emits exactly one
     * business state per product, and it is one of the three.
     */
    public function test_p8_p9_every_surface_reads_one_business_state(): void
    {
        $cases = [
            [true, 10.0, 0.0, 'in_stock'],
            [true, 0.0, 0.0, 'negative_allowed'],
            [false, 0.0, 0.0, 'out_of_stock'],
        ];

        foreach ($cases as [$neg, $onHand, $res, $expected]) {
            $p = $this->product(allowNegative: $neg);
            if ($onHand > 0 || $res > 0) {
                $this->stock($p, $onHand, $res);
            }

            $state = $this->apiState($p);

            self::assertSame($expected, $state);
            self::assertContains($state, ProductAvailability::values(),
                'A surface received a state outside the business contract.');
        }
    }

    // ── P10 — filter parity ───────────────────────────────────────────────────

    /**
     * P10 — each filter returns EXACTLY the products whose projected badge matches.
     *
     * Asserted in both directions, so neither a filter that is too narrow nor one that is
     * too wide can pass.
     */
    public function test_p10_filter_population_equals_rendered_state(): void
    {
        $expected = ['in_stock' => [], 'negative_allowed' => [], 'out_of_stock' => []];

        $mk = function (bool $neg, ?float $onHand, float $res = 0.0) use (&$expected): void {
            $p = $this->product(allowNegative: $neg);
            if ($onHand !== null) {
                $this->stock($p, $onHand, $res);
            }
            $expected[$this->apiState($p)][] = $p->id;
        };

        $mk(false, 10.0);          // in_stock
        $mk(true, 10.0);           // in_stock (policy irrelevant when positive)
        $mk(true, 5.0, 5.0);       // negative_allowed
        $mk(true, 1.0, 6.0);       // negative_allowed (negative)
        $mk(true, null);           // negative_allowed (no row)
        $mk(false, 5.0, 5.0);      // out_of_stock
        $mk(false, 1.0, 6.0);      // out_of_stock (negative)
        $mk(false, null);          // out_of_stock (no row)

        foreach ($expected as $state => $ids) {
            sort($ids);
            $actual = $this->filtered($state);
            sort($actual);

            self::assertSame($ids, $actual,
                "Filter '{$state}' does not return exactly the products whose badge reads '{$state}'.");
        }

        // Collectively exhaustive: every product landed in exactly one bucket.
        $total = array_sum(array_map('count', $expected));
        self::assertSame(8, $total, 'A product was classified into zero or multiple states.');
    }

    // ── P11 — the data-platform contract survives ─────────────────────────────

    /**
     * P11 — `AvailabilityState::Untracked` is INTACT and still reachable.
     *
     * The business projection must not have been achieved by deleting the distinction the
     * data platform depends on. `InventorySummaryService` and the inventory-layer endpoint
     * still need "no inventory record" to be its own answer.
     */
    public function test_p11_data_platform_untracked_contract_is_intact(): void
    {
        self::assertSame('untracked', AvailabilityState::Untracked->value);
        self::assertSame(AvailabilityState::Untracked, AvailabilityState::fromAvailable(null));
        self::assertSame(AvailabilityState::OutOfStock, AvailabilityState::fromAvailable(0.0));
        self::assertSame(AvailabilityState::InStock, AvailabilityState::fromAvailable(1.0));

        // And it is deliberately NOT part of the business contract.
        self::assertNotContains('untracked', ProductAvailability::values());
    }

    // ── P12 — tenant isolation ────────────────────────────────────────────────

    /**
     * P12 — the inventory aggregate is scoped to the caller's company (F-INV-10).
     *
     * The aggregate summed `inventory_items` across every company, so another company's
     * rows inflated this product's Available. The product LIST was company-scoped; the
     * NUMBER on each row was not.
     *
     * Scoping is by ACTOR company because `products` has no `company_id` — its tenancy
     * runs through `brand_id -> brands.company_id`. The actor must therefore be
     * authenticated and NOT hold the system role, since an unrestricted actor is
     * deliberately exempt from scoping (the canonical contract, preserved).
     */
    public function test_p12_availability_is_tenant_isolated(): void
    {
        $brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $p = $this->product(allowNegative: false);
        $p->update(['brand_id' => $brand->id]);

        $other = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $other->id]);

        InventoryItem::query()->create([
            'company_id' => $other->id,
            'warehouse_id' => $otherWarehouse->id,
            'product_id' => $p->id,
            'on_hand_qty' => 999.0,
            'reserved_qty' => 0.0,
        ]);

        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        self::assertSame('out_of_stock', $this->apiState($p),
            "Another company's inventory leaked into this product's availability.");
        self::assertNotContains($p->id, $this->filtered('in_stock'));
        self::assertContains($p->id, $this->filtered('out_of_stock'));
    }

    /**
     * P12b — same-company data is UNAFFECTED by the scoping.
     *
     * The repair must not have been achieved by filtering out legitimate rows: a restricted
     * actor still sees their own company's inventory in full.
     */
    public function test_p12b_same_company_inventory_is_unaffected_by_scoping(): void
    {
        $brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $p = $this->product(allowNegative: false);
        $p->update(['brand_id' => $brand->id]);
        $this->stock($p, 10.0);

        $this->actingAs(User::factory()->create(['company_id' => $this->company->id]));

        self::assertSame('in_stock', $this->apiState($p));
        self::assertContains($p->id, $this->filtered('in_stock'));
    }

    // ── exclusivity / exhaustiveness of the projection itself ─────────────────

    /**
     * The three states are mutually exclusive and collectively exhaustive across the whole
     * input domain — asserted directly on the projection rather than only through the API.
     */
    public function test_projection_is_mutually_exclusive_and_exhaustive(): void
    {
        $inputs = [null, -1000.0, -0.0001, 0.0, 0.0001, 1000.0];

        foreach ($inputs as $available) {
            foreach ([true, false] as $allowNegative) {
                $state = ProductAvailability::project($available, $allowNegative);

                $matches = array_filter(
                    ProductAvailability::cases(),
                    static fn (ProductAvailability $c): bool => $c === $state,
                );

                self::assertCount(1, $matches, 'Projection produced an ambiguous state.');
                self::assertContains($state->value, ProductAvailability::values());

                // The rule, restated independently of the implementation.
                $qty = $available ?? 0.0;
                $want = $qty > 0.0
                    ? ProductAvailability::InStock
                    : ($allowNegative ? ProductAvailability::NegativeAllowed : ProductAvailability::OutOfStock);

                self::assertSame($want, $state,
                    sprintf('available=%s allowNegative=%s', var_export($available, true), var_export($allowNegative, true)));
            }
        }

        self::assertCount(3, ProductAvailability::cases(), 'A fourth business state appeared.');
    }
}
