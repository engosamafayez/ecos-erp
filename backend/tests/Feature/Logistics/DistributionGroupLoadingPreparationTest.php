<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Distribution\Domain\Services\DistributionAggregationService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DISTRIBUTION-GROUP-LOADING-PREPARATION-LP1-REQUIRED-PROJECTION-001.
 *
 * LP-1: the products required by ONE Distribution Group, so a warehouse can
 * begin separating them before a Vehicle and Driver are known.
 *
 * ┌─ WHAT THIS SUITE DELIBERATELY DOES NOT RE-TEST ──────────────────────────┐
 * │ DistributionCoreTest already proves the canonical aggregation sums        │
 * │ order lines correctly window-wide. DistributionWarehouseScopedReadsTest   │
 * │ already proves zones, slots and the ORDER pool are warehouse-scoped.      │
 * │                                                                          │
 * │ Neither covers `productAggregation` narrowed to a GROUP, nor warehouse-   │
 * │ scoped, nor eligibility-filtered. That gap is what this file closes, and  │
 * │ nothing else.                                                            │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The projection is READ-ONLY. The last test proves it writes nothing at all.
 */
class DistributionGroupLoadingPreparationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouseA;

    private Warehouse $warehouseB;

    private int $zoneMaadi;

    private int $zoneNasr;

    private Product $honey;

    private Product $coffee;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->warehouseA = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->warehouseB = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi');
        $this->zoneNasr = $this->zone('Nasr City');
        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneMaadi);
        $this->city($governorate, 'Nasr City', 'مدينة نصر', $this->zoneNasr);

        $this->honey = Product::factory()->create();
        $this->coffee = Product::factory()->create();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1. The endpoint consumes the canonical aggregation — no second engine
    // ─────────────────────────────────────────────────────────────────────────

    public function test_group_required_products_are_the_canonical_aggregation_result(): void
    {
        $o1 = $this->order($this->warehouseA, 'Maadi');
        $this->line($o1, $this->honey->id, 10);
        $o2 = $this->order($this->warehouseA, 'Maadi');
        $this->line($o2, $this->honey->id, 30);
        $this->line($o2, $this->coffee->id, 5);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-LP1');
        $this->addZone($group['id'], $this->zoneMaadi);

        $viaApi = $this->requiredProducts($group['id'], $this->warehouseA);

        // Two orders, one zone, one warehouse: honey 10 + 30, coffee 5.
        self::assertSame(40.0, $viaApi[$this->honey->id]);
        self::assertSame(5.0, $viaApi[$this->coffee->id]);

        // And the SAME numbers straight from the service the architecture audit
        // named canonical. If the endpoint ever grew its own arithmetic, these
        // two would drift apart.
        $viaService = [];
        foreach (
            app(DistributionAggregationService::class)->productAggregation(
                $this->windowId(),
                null,
                $group['id'],
                $this->warehouseA->id,
            ) as $row
        ) {
            $viaService[(string) $row['product_id']] = (float) $row['total_quantity'];
        }

        self::assertSame($viaService, $viaApi);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2. Warehouse isolation — the same Zone planned by two warehouses
    // ─────────────────────────────────────────────────────────────────────────

    public function test_two_warehouses_planning_the_same_zone_get_only_their_own_work(): void
    {
        $a = $this->order($this->warehouseA, 'Maadi');
        $this->line($a, $this->honey->id, 7);

        $b = $this->order($this->warehouseB, 'Maadi');
        $this->line($b, $this->honey->id, 100);
        $this->line($b, $this->coffee->id, 3);

        $this->collect();

        $groupA = $this->group($this->warehouseA, 'DG-A');
        $this->addZone($groupA['id'], $this->zoneMaadi);
        $groupB = $this->group($this->warehouseB, 'DG-B');
        $this->addZone($groupB['id'], $this->zoneMaadi);

        $requiredA = $this->requiredProducts($groupA['id'], $this->warehouseA);
        $requiredB = $this->requiredProducts($groupB['id'], $this->warehouseB);

        // A sees ONLY its own 7 — never B's 100 for the same product in the
        // same geography. This is the whole point of warehouse ownership.
        self::assertSame([$this->honey->id => 7.0], $requiredA);

        self::assertSame(100.0, $requiredB[$this->honey->id]);
        self::assertSame(3.0, $requiredB[$this->coffee->id]);
        self::assertArrayNotHasKey($this->coffee->id, $requiredA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3-4. Eligibility — both halves of the Preparation contract
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_order_that_becomes_ineligible_stops_contributing(): void
    {
        $keep = $this->order($this->warehouseA, 'Maadi');
        $this->line($keep, $this->honey->id, 4);
        $drop = $this->order($this->warehouseA, 'Maadi');
        $this->line($drop, $this->honey->id, 96);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-ELIG');
        $this->addZone($group['id'], $this->zoneMaadi);

        self::assertSame(100.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);

        // Status change ONLY. No Distribution row is rewritten — the projection
        // is a filtered view, so the change lands without any Distribution write.
        DB::table('orders')->where('id', $drop->id)->update(['status' => 'cancelled']);

        self::assertSame(4.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);

        // Membership itself is untouched: the order is still IN the group, it is
        // simply no longer eligible work. Nothing was silently removed.
        self::assertDatabaseHas('distribution_window_orders', [
            'order_id' => $drop->id,
            'virtual_slot_id' => $group['id'],
        ]);
    }

    public function test_a_postponed_preparation_member_stops_contributing(): void
    {
        $keep = $this->order($this->warehouseA, 'Maadi');
        $this->line($keep, $this->honey->id, 6);
        $postponed = $this->order($this->warehouseA, 'Maadi');
        $this->line($postponed, $this->honey->id, 60);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-POST');
        $this->addZone($group['id'], $this->zoneMaadi);

        self::assertSame(66.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);

        // The OTHER half of the eligibility contract: an active wave membership
        // carrying a postponement takes the order out of the cycle even though
        // its status never changed.
        $this->postpone($postponed);

        self::assertSame(6.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5. Empty Group
    // ─────────────────────────────────────────────────────────────────────────

    public function test_an_empty_group_reports_no_required_products(): void
    {
        $o = $this->order($this->warehouseA, 'Nasr City');
        $this->line($o, $this->honey->id, 9);

        $this->collect();

        // A Group that owns no Zone owns no orders. That is a legitimate state,
        // and it must answer with an empty list rather than an error or the
        // window's products.
        $empty = $this->group($this->warehouseA, 'DG-EMPTY');

        self::assertSame([], $this->rawRequired($empty['id'], $this->warehouseA));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 6. No WAVE-scoped prepared quantity may leak into the Group projection
    //
    // SUPERSEDED IN PART BY LP-2, DELIBERATELY AND WITH APPROVAL.
    //
    // This test used to assert that NO prepared/remaining figure appeared here at
    // all. That was correct while the only Prepared in existence was Preparation's
    // (wave, product) number, which cannot be split across the Groups that share a
    // wave. LP-2 does not split it — it records a SEPARATE, Group-owned quantity.
    // So the half of this test that forbade a Group-owned figure is now obsolete,
    // and the half that matters is not: Preparation's wave number must still never
    // appear on this payload under any name.
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_projection_reports_the_groups_own_prepared_and_never_the_waves(): void
    {
        $o = $this->order($this->warehouseA, 'Maadi');
        $this->line($o, $this->honey->id, 12);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-NOPREP');
        $this->addZone($group['id'], $this->zoneMaadi);

        // A wave-level Prepared exists for the SAME product, with a DIFFERENT value.
        // If the Group projection ever started borrowing Preparation's number, this
        // fixture is what would make it visible.
        $this->waveDemandFor($this->honey->id, required: 40, prepared: 37);

        $rows = $this->rawRequired($group['id'], $this->warehouseA);
        self::assertNotSame([], $rows);

        foreach ($rows as $row) {
            // The Group's OWN fact is present and is its own — untouched by the
            // wave's 37, and zero because nobody has prepared for THIS Group yet.
            self::assertArrayHasKey('prepared_qty', $row);
            self::assertSame(0.0, (float) $row['prepared_qty'], 'the wave\'s 37 must not leak in');
            self::assertSame(12.0, (float) $row['remaining_qty'], 'Remaining derives from the GROUP\'s Required and Prepared');

            // Preparation's own vocabulary must not appear here under any name. A
            // field called `wave_prepared_qty` or `quantity_prepared` on a
            // Group-scoped payload is exactly the conflation LP-1 refused to ship.
            foreach (['quantity_prepared', 'wave_prepared_qty', 'wave_required_qty', 'completion_pct'] as $forbidden) {
                self::assertArrayNotHasKey(
                    $forbidden,
                    $row,
                    "The Group projection must not carry Preparation's '{$forbidden}'.",
                );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 7. The unit of measure travels with the quantity
    // ─────────────────────────────────────────────────────────────────────────

    public function test_the_unit_of_measure_travels_with_the_required_quantity(): void
    {
        $o = $this->order($this->warehouseA, 'Maadi');
        $this->line($o, $this->honey->id, 2);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-UNIT');
        $this->addZone($group['id'], $this->zoneMaadi);

        $row = $this->rawRequired($group['id'], $this->warehouseA)[0];

        $expected = DB::table('units')
            ->where('id', DB::table('products')->where('id', $this->honey->id)->value('unit_id'))
            ->first(['code', 'symbol']);

        self::assertSame($expected->code, $row['unit_code']);
        self::assertSame($expected->symbol, $row['unit_symbol']);

        // The quantity is unchanged by the unit join — the additive column must
        // not have altered the GROUP BY granularity.
        self::assertSame(2.0, (float) $row['total_quantity']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 8. It is a projection: it writes nothing
    // ─────────────────────────────────────────────────────────────────────────

    public function test_reading_loading_preparation_writes_nothing(): void
    {
        $o = $this->order($this->warehouseA, 'Maadi');
        $this->line($o, $this->honey->id, 5);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-READONLY');
        $this->addZone($group['id'], $this->zoneMaadi);

        $before = $this->rowCounts();
        $orderStatusBefore = DB::table('orders')->where('id', $o->id)->value('status');

        // Read it repeatedly — idempotent by construction, but proven, not assumed.
        $this->requiredProducts($group['id'], $this->warehouseA);
        $this->requiredProducts($group['id'], $this->warehouseA);

        self::assertSame($before, $this->rowCounts());
        self::assertSame($orderStatusBefore, DB::table('orders')->where('id', $o->id)->value('status'));
    }

    // ═════════════════════════════════════════════════════════════════════════
    // LP-1.0 — ELIGIBILITY REPAIR
    // TASK-OPERATIONS-DISTRIBUTION-GROUP-LOADING-PREPARATION-LP1-ELIGIBILITY-REPAIR-001
    //
    // Starting a preparation wave moves every order in it to `ready_for_dispatch`
    // — the status that MEANS "prepared, waiting to be loaded". The narrow
    // `constrainToEligible` predicate excluded it, so a Group emptied itself at
    // exactly the moment its work became loadable. These nine tests fix the
    // boundary in place and prove nothing else moved with it.
    // ═════════════════════════════════════════════════════════════════════════

    public function test_a_ready_for_dispatch_order_is_visible_to_loading_preparation(): void
    {
        $o = $this->order($this->warehouseA, 'Maadi');
        $this->line($o, $this->honey->id, 7);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-RFD');
        $this->addZone($group['id'], $this->zoneMaadi);

        // Before Preparation: visible, as LP-1 always was.
        self::assertSame(7.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);

        // Preparation completes. This is the ONLY change — no Distribution write,
        // no membership change, no wave fixture needed: the status is the whole
        // mechanism, exactly as HandlePreparationWaveStarted produces it.
        $this->setStatus($o, 'ready_for_dispatch');

        // The regression this task exists to fix: this used to return [].
        self::assertSame(7.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);
    }

    public function test_in_progress_and_confirmed_remain_visible_alongside_ready_for_dispatch(): void
    {
        $inProgress = $this->order($this->warehouseA, 'Maadi');
        $this->line($inProgress, $this->honey->id, 1);
        $confirmed = $this->order($this->warehouseA, 'Maadi');
        $this->line($confirmed, $this->honey->id, 20);
        $prepared = $this->order($this->warehouseA, 'Maadi');
        $this->line($prepared, $this->honey->id, 300);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-MIX');
        $this->addZone($group['id'], $this->zoneMaadi);

        $this->setStatus($confirmed, 'confirmed');
        $this->setStatus($prepared, 'ready_for_dispatch');

        // The widened predicate is a SUPERSET, not a replacement: a Group mid-cycle
        // holds all three states at once and must report all three.
        self::assertSame(321.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);
    }

    public function test_a_postponed_ready_for_dispatch_order_stays_excluded(): void
    {
        $keep = $this->order($this->warehouseA, 'Maadi');
        $this->line($keep, $this->honey->id, 5);
        $postponed = $this->order($this->warehouseA, 'Maadi');
        $this->line($postponed, $this->honey->id, 500);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-RFD-POST');
        $this->addZone($group['id'], $this->zoneMaadi);

        // Both halves move at once — the hardest case for the new predicate, and
        // the one that would silently regress if it had been written as a plain
        // status list instead of composing excludePostponed().
        $this->setStatus($keep, 'ready_for_dispatch');
        $this->setStatus($postponed, 'ready_for_dispatch');
        $this->postpone($postponed);

        self::assertSame(5.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);
    }

    public function test_a_cancelled_ready_for_dispatch_order_stays_excluded(): void
    {
        $keep = $this->order($this->warehouseA, 'Maadi');
        $this->line($keep, $this->honey->id, 8);
        $drop = $this->order($this->warehouseA, 'Maadi');
        $this->line($drop, $this->honey->id, 800);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-RFD-CANC');
        $this->addZone($group['id'], $this->zoneMaadi);

        $this->setStatus($keep, 'ready_for_dispatch');
        $this->setStatus($drop, 'ready_for_dispatch');
        self::assertSame(808.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);

        // Widening must not degrade into "everything after preparation counts".
        $this->setStatus($drop, 'cancelled');

        self::assertSame(8.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);
    }

    public function test_statuses_past_loading_are_not_loading_eligible(): void
    {
        $o = $this->order($this->warehouseA, 'Maadi');
        $this->line($o, $this->honey->id, 11);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-PAST');
        $this->addZone($group['id'], $this->zoneMaadi);

        // The list is `ready_for_dispatch` and nothing beyond it. An order already
        // on a vehicle or delivered is past loading preparation, not awaiting it.
        foreach (['out_for_delivery', 'delivered', 'returned'] as $status) {
            $this->setStatus($o, $status);
            self::assertSame(
                [],
                $this->rawRequired($group['id'], $this->warehouseA),
                "Status [{$status}] must not be loading-eligible.",
            );
        }
    }

    public function test_ready_for_dispatch_work_stays_inside_its_own_warehouse(): void
    {
        $mine = $this->order($this->warehouseA, 'Maadi');
        $this->line($mine, $this->honey->id, 3);
        $theirs = $this->order($this->warehouseB, 'Maadi');
        $this->line($theirs, $this->honey->id, 900);
        $this->line($theirs, $this->coffee->id, 900);

        $this->collect();
        $groupA = $this->group($this->warehouseA, 'DG-RFD-WHA');
        $this->addZone($groupA['id'], $this->zoneMaadi);

        // Both warehouses finish preparing the same Zone on the same day. The
        // widened predicate must not become a hole in the Part 5B boundary.
        $this->setStatus($mine, 'ready_for_dispatch');
        $this->setStatus($theirs, 'ready_for_dispatch');

        $rows = $this->requiredProducts($groupA['id'], $this->warehouseA);

        self::assertSame(3.0, $rows[$this->honey->id]);
        self::assertArrayNotHasKey($this->coffee->id, $rows);
    }

    public function test_a_foreign_tenant_cannot_read_another_companys_loading_preparation(): void
    {
        $o = $this->order($this->warehouseA, 'Maadi');
        $this->line($o, $this->honey->id, 4);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-RFD-TEN');
        $this->addZone($group['id'], $this->zoneMaadi);
        $this->setStatus($o, 'ready_for_dispatch');

        $windowId = $this->windowId();
        $outsider = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        // The tenant boundary is the Window's own company check, which the widened
        // predicate composes with rather than replaces.
        $this->actingAs($outsider)
            ->getJson(self::BASE."/windows/{$windowId}/products?slot_id={$group['id']}&warehouse_id={$this->warehouseA->id}")
            ->assertNotFound();
    }

    public function test_the_group_headline_count_and_its_order_list_agree_after_preparation(): void
    {
        foreach ([2.0, 3.0] as $qty) {
            $o = $this->order($this->warehouseA, 'Maadi');
            $this->line($o, $this->honey->id, $qty);
            $this->collect();
            $this->setStatus($o, 'ready_for_dispatch');
        }

        $group = $this->group($this->warehouseA, 'DG-AGREE');
        $this->addZone($group['id'], $this->zoneMaadi);

        $windowId = $this->windowId();

        $slots = $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouseA->id)
            ->assertOk()->json('data.slots');

        $mine = collect($slots)->firstWhere('slot_id', $group['id']);

        $orders = $this->actingAs($this->userFor())
            ->getJson(self::BASE."/windows/{$windowId}/orders?slot_id={$group['id']}&warehouse_id={$this->warehouseA->id}")
            ->assertOk()->json('data');

        // slotRollup, slotOrderCounts and orders() are three queries the SAME card
        // renders side by side. Widening any subset of them would print one number
        // above a list of a different length.
        self::assertSame(2, (int) $mine['orders_count'], 'slotRollup');
        self::assertSame(2, (int) $mine['demand_orders'], 'slotOrderCounts');
        self::assertCount(2, $orders, 'orders()');
        self::assertSame(5.0, $this->requiredProducts($group['id'], $this->warehouseA)[$this->honey->id]);
    }

    public function test_unrelated_consumers_keep_the_narrow_predicate(): void
    {
        $grouped = $this->order($this->warehouseA, 'Maadi');
        $this->line($grouped, $this->honey->id, 6);

        $this->collect();
        $group = $this->group($this->warehouseA, 'DG-NARROW');
        $this->addZone($group['id'], $this->zoneMaadi);
        $this->setStatus($grouped, 'ready_for_dispatch');

        // 1. zoneSummaries — the PLANNING board — deliberately still asks the
        //    narrower question, so a fully-prepared Zone leaves it while its Group
        //    keeps reporting the work.
        $current = $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$this->warehouseA->id)
            ->assertOk()->json('data');

        self::assertSame(
            [],
            collect($current['zones'])->where('zone_id', $this->zoneMaadi)->values()->all(),
            'zoneSummaries must NOT widen.',
        );
        self::assertSame(
            1,
            (int) collect($current['slots'])->firstWhere('slot_id', $group['id'])['orders_count'],
            'slotSummaries MUST widen.',
        );

        // 2. Collection — a WRITE path — must not newly ingest post-preparation
        //    orders. An order that finished Preparation without ever being
        //    collected stays uncollected; that hole is a reported follow-up, not
        //    something LP-1.0 silently closes.
        //
        //    The City is bound EXPLICITLY first. OrderCityBinder is itself on the
        //    narrow predicate, so an unbound order would fail to collect for a
        //    second, uninteresting reason — and the assertion would pass without
        //    proving anything about status. Binding it up front makes status the
        //    only variable left.
        $never = $this->order($this->warehouseA, 'Nasr City');
        $this->line($never, $this->honey->id, 99);
        DB::table('orders')->where('id', $never->id)->update([
            'logistics_city_id' => DB::table('logistics_cities')->where('name_en', 'Nasr City')->value('id'),
        ]);
        $this->setStatus($never, 'ready_for_dispatch');

        $this->collect();

        self::assertDatabaseMissing('distribution_window_orders', ['order_id' => $never->id]);

        // ...and the control: the SAME order, bound identically, collects the moment
        // its status is one the narrow predicate accepts. Without this the assertion
        // above could be satisfied by any unrelated collection failure.
        $this->setStatus($never, 'in_progress');
        $this->collect();

        self::assertDatabaseHas('distribution_window_orders', ['order_id' => $never->id]);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // LP-2 — GROUP + PRODUCT PREPARED
    // TASK-OPERATIONS-GROUP-LOADING-PREPARATION-IMPLEMENTATION-001
    //
    //   Required  — LIVE, canonical (productAggregation), never stored
    //   Prepared  — declared by the operator, stored at (Group, Product), ABSOLUTE SET
    //   Remaining — max(0, Required − Prepared), DERIVED, never stored
    //   Ceiling   — Prepared <= Required, recomputed INSIDE the transaction lock
    // ═════════════════════════════════════════════════════════════════════════

    public function test_group_prepared_is_created_once_then_updated_never_duplicated(): void
    {
        [$group, $product] = $this->preparableGroup('DG-P-CREATE', 10.0);

        // 1. Created.
        $this->setPrepared($group, $product, 4)->assertOk();
        self::assertSame(4.0, $this->storedPrepared($group, $product));
        self::assertSame(1, $this->preparationRowCount($group));

        // 2. Updated in place — the same (Group, Product) never gains a second row.
        $this->setPrepared($group, $product, 6)->assertOk();
        self::assertSame(6.0, $this->storedPrepared($group, $product));
        self::assertSame(1, $this->preparationRowCount($group));

        // 3. IDEMPOTENT. The identical request writes the identical number — absolute
        //    set, not accumulation. This is what makes a retry after a UI timeout safe
        //    without any idempotency-key infrastructure.
        $this->setPrepared($group, $product, 6)->assertOk();
        self::assertSame(6.0, $this->storedPrepared($group, $product));
        self::assertSame(1, $this->preparationRowCount($group));
    }

    public function test_the_read_model_returns_required_prepared_remaining_and_unit(): void
    {
        [$group, $product] = $this->preparableGroup('DG-P-READ', 10.0);
        $this->setPrepared($group, $product, 3)->assertOk();

        $row = $this->rawRequired($group['id'], $this->warehouseA)[0];

        // Every figure arrives from the server on ONE row. The client computes nothing.
        self::assertSame($product, $row['product_id']);
        self::assertArrayHasKey('product_sku', $row);
        self::assertArrayHasKey('unit_symbol', $row);
        self::assertSame(10.0, (float) $row['total_quantity'], 'Required — canonical aggregation');
        self::assertSame(3.0, (float) $row['prepared_qty'], 'Prepared — Group storage');
        self::assertSame(7.0, (float) $row['remaining_qty'], 'Remaining — DERIVED');
        self::assertSame(0.0, (float) $row['over_prepared_qty']);

        // Required is the CANONICAL aggregation, not a second engine: identical to the
        // service's own output for the same Group.
        $canonical = app(DistributionAggregationService::class)->productAggregation(
            $this->windowId(),
            null,
            $group['id'],
            $this->warehouseA->id,
        );
        self::assertSame((float) $canonical[0]['total_quantity'], (float) $row['total_quantity']);

        // And Remaining is NEVER read from storage. Corrupt the stored quantity
        // directly and the API still derives from Required − Prepared.
        DB::table('distribution_group_product_preparation')
            ->where('virtual_slot_id', $group['id'])
            ->update(['prepared_qty' => 8]);

        self::assertSame(2.0, (float) $this->rawRequired($group['id'], $this->warehouseA)[0]['remaining_qty']);
    }

    public function test_prepared_cannot_exceed_required_and_cannot_be_negative(): void
    {
        [$group, $product] = $this->preparableGroup('DG-P-BOUNDS', 5.0);

        // Above the ceiling — refused, and nothing is written.
        $this->setPrepared($group, $product, 5.0001)->assertStatus(422);
        self::assertSame(0, $this->preparationRowCount($group));

        // Exactly at the ceiling — allowed. A float ceiling must not refuse equality.
        $this->setPrepared($group, $product, 5)->assertOk();
        self::assertSame(5.0, $this->storedPrepared($group, $product));

        // Below zero — refused by validation before any lock is taken.
        $this->setPrepared($group, $product, -1)->assertStatus(422);
        self::assertSame(5.0, $this->storedPrepared($group, $product), 'the refused write changed nothing');

        // Zero is legitimate: it is how an operator records that separated stock was
        // put back. It is NOT treated as "delete the record".
        $this->setPrepared($group, $product, 0)->assertOk();
        self::assertSame(0.0, $this->storedPrepared($group, $product));
        self::assertSame(1, $this->preparationRowCount($group));
    }

    public function test_the_ceiling_is_evaluated_against_required_as_it_is_at_write_time(): void
    {
        [$group, $product, $orders] = $this->preparableGroup('DG-P-LIVE', 10.0, withOrders: true);

        $this->setPrepared($group, $product, 10)->assertOk();

        // Required now FALLS — an order is cancelled after the client last read it.
        // A ceiling captured before the write would still say 10 and let this through.
        $this->setStatus($orders[0], 'cancelled');

        $this->setPrepared($group, $product, 10)->assertStatus(422);

        // The already-recorded 10 is NOT clawed back — the floor's number is never
        // discarded — but it is now visibly over-prepared against the reduced Required.
        $row = $this->rawRequired($group['id'], $this->warehouseA)[0];
        self::assertSame(10.0, (float) $row['prepared_qty']);
        self::assertSame(0.0, (float) $row['remaining_qty']);
        self::assertGreaterThan(0.0, (float) $row['over_prepared_qty']);
    }

    public function test_the_write_takes_a_row_lock_and_recomputes_required_inside_it(): void
    {
        [$group, $product] = $this->preparableGroup('DG-P-LOCK', 4.0);

        $sql = [];
        DB::listen(static function ($q) use (&$sql): void {
            $sql[] = strtolower($q->sql);
        });

        $this->setPrepared($group, $product, 2)->assertOk();

        $locked = array_filter($sql, static fn (string $s): bool => str_contains($s, 'for update'));
        self::assertNotEmpty($locked, 'the write must take a row lock');
        self::assertTrue(
            (bool) array_filter($locked, static fn (string $s): bool => str_contains($s, 'distribution_virtual_slots')),
            'the lock must be on the Group — it is what serialises writes AND removes the first-write create race',
        );

        // Required is re-derived inside that window, not read from any stored column.
        self::assertTrue(
            (bool) array_filter($sql, static fn (string $s): bool => str_contains($s, 'sum(ol.quantity)')),
            'live Required must be recomputed during the write',
        );
    }

    public function test_two_sequential_writes_cannot_accumulate_past_required(): void
    {
        [$group, $product] = $this->preparableGroup('DG-P-CONC', 7.0);

        // The scenario the contract names: two operators each submit 5 against a
        // Required of 7. Absolute-set means the row reads 5 — never 10 — so the
        // accumulation that would breach the ceiling cannot occur by construction.
        $this->setPrepared($group, $product, 5)->assertOk();
        $this->setPrepared($group, $product, 5)->assertOk();

        self::assertSame(5.0, $this->storedPrepared($group, $product));
        self::assertSame(1, $this->preparationRowCount($group));
        self::assertLessThanOrEqual(7.0, $this->storedPrepared($group, $product));
    }

    public function test_a_foreign_tenant_can_neither_read_nor_write_group_prepared(): void
    {
        [$group, $product] = $this->preparableGroup('DG-P-TENANT', 3.0);
        $this->setPrepared($group, $product, 1)->assertOk();

        $windowId = $this->windowId();
        $outsider = User::factory()->create(['company_id' => Company::factory()->create()->id]);

        // NOT FOUND, never 403 — a foreign Group must read as non-existent so the
        // endpoint cannot be used to probe which Group ids are real.
        $this->actingAs($outsider)
            ->getJson(self::BASE."/windows/{$windowId}/products?slot_id={$group['id']}")
            ->assertNotFound();

        $this->actingAs($outsider)
            ->putJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/preparation/{$product}", ['prepared_qty' => 3])
            ->assertNotFound();

        self::assertSame(1.0, $this->storedPrepared($group, $product), 'the foreign write changed nothing');
    }

    public function test_a_group_from_another_warehouse_cannot_be_written_through_this_one(): void
    {
        [$groupA, $product] = $this->preparableGroup('DG-P-WHA', 2.0);

        // Warehouse B's own Group, in the same Window and the same Zone — the exact
        // shape Part 5B exists to keep apart.
        $b = $this->order($this->warehouseB, 'Maadi');
        $this->line($b, $this->coffee->id, 50);
        $this->collect();
        $groupB = $this->group($this->warehouseB, 'DG-P-WHB');
        $this->addZone($groupB['id'], $this->zoneMaadi);

        // Warehouse A's Group never sees B's product...
        $skus = array_column($this->rawRequired($groupA['id'], $this->warehouseA), 'product_id');
        self::assertNotContains($this->coffee->id, $skus);

        // ...and cannot record Prepared for it, because it is not required there.
        $this->setPrepared($groupA, $this->coffee->id, 1)->assertStatus(422);

        self::assertSame(0, (int) DB::table('distribution_group_product_preparation')
            ->where('virtual_slot_id', $groupA['id'])
            ->where('product_id', $this->coffee->id)
            ->count());

        // The two Groups' Prepared records stay entirely separate.
        $this->setPrepared($groupA, $product, 2)->assertOk();
        $this->setPrepared($groupB, $this->coffee->id, 50)->assertOk();
        self::assertSame(2.0, $this->storedPrepared($groupA, $product));
        self::assertSame(50.0, $this->storedPrepared($groupB, $this->coffee->id));
    }

    public function test_a_group_membership_change_refreshes_required_and_never_moves_prepared(): void
    {
        [$group, $product] = $this->preparableGroup('DG-P-MEMBER', 10.0);
        $this->setPrepared($group, $product, 6)->assertOk();

        // Detach the Zone — the Group's orders leave, so Required re-derives to zero.
        $windowId = $this->windowId();
        $this->actingAs($this->userFor())
            ->deleteJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/zones/{$this->zoneMaadi}")
            ->assertOk();

        // PREPARED IS NOT DELETED, NOT RECALCULATED FROM ORDERS, NOT MOVED. And it is
        // still VISIBLE — a retained record nobody can see would be the worst of both,
        // because the stock is physically on this Group's pallet.
        $rows = $this->rawRequired($group['id'], $this->warehouseA);
        self::assertCount(1, $rows);
        self::assertSame(0.0, (float) $rows[0]['total_quantity'], 'Required re-derived');
        self::assertSame(6.0, (float) $rows[0]['prepared_qty'], 'Prepared preserved');
        self::assertSame(0.0, (float) $rows[0]['remaining_qty']);
        self::assertSame(6.0, (float) $rows[0]['over_prepared_qty'], 'surfaced, not hidden behind a floored Remaining');
        self::assertSame(6.0, $this->storedPrepared($group, $product));

        // Re-attach: Required returns, Prepared is exactly as the operator left it.
        $this->addZone($group['id'], $this->zoneMaadi);
        $rows = $this->rawRequired($group['id'], $this->warehouseA);
        self::assertSame(10.0, (float) $rows[0]['total_quantity']);
        self::assertSame(6.0, (float) $rows[0]['prepared_qty']);
        self::assertSame(4.0, (float) $rows[0]['remaining_qty']);
    }

    public function test_recording_prepared_writes_nothing_outside_its_own_table(): void
    {
        [$group, $product, $orders] = $this->preparableGroup('DG-P-SIDE', 9.0, withOrders: true);

        $before = $this->rowCounts() + [
            'wave_product_demand' => DB::table('wave_product_demand')->count(),
            'preparation_wave_items' => DB::table('preparation_wave_items')->count(),
            'preparation_wave_orders' => DB::table('preparation_wave_orders')->count(),
            'inventory_items' => DB::table('inventory_items')->count(),
        ];
        $statusBefore = DB::table('orders')->where('id', $orders[0]->id)->value('status');

        $this->setPrepared($group, $product, 9)->assertOk();

        $after = $this->rowCounts() + [
            'wave_product_demand' => DB::table('wave_product_demand')->count(),
            'preparation_wave_items' => DB::table('preparation_wave_items')->count(),
            'preparation_wave_orders' => DB::table('preparation_wave_orders')->count(),
            'inventory_items' => DB::table('inventory_items')->count(),
        ];

        // Inventory, Preparation, Loading, the vehicle tables and the Distribution
        // membership tables are all untouched. The ONLY new row is the Group Prepared
        // record itself, which rowCounts() deliberately does not include.
        self::assertSame($before, $after);
        self::assertSame($statusBefore, DB::table('orders')->where('id', $orders[0]->id)->value('status'));
        self::assertSame(1, $this->preparationRowCount($group));
    }

    public function test_lp1_required_only_behaviour_is_unchanged_for_window_and_zone_reads(): void
    {
        [$group, $product] = $this->preparableGroup('DG-P-LP1', 5.0);
        $this->setPrepared($group, $product, 2)->assertOk();

        $windowId = $this->windowId();

        // WITHOUT a slot_id the payload is byte-identical to LP-1: Required only. The
        // window-wide and zone-wide callers gained no fields and lost none.
        $windowWide = $this->actingAs($this->userFor())
            ->getJson(self::BASE."/windows/{$windowId}/products")
            ->assertOk()->json('data');

        self::assertNotEmpty($windowWide);
        foreach (['prepared_qty', 'remaining_qty', 'over_prepared_qty'] as $groupOnly) {
            self::assertArrayNotHasKey($groupOnly, $windowWide[0], "{$groupOnly} is Group-scoped and must not leak into the window-wide read");
        }
        self::assertArrayHasKey('total_quantity', $windowWide[0]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * A Group holding one product at a known Required quantity, ready to prepare.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: list<Order>}
     */
    private function preparableGroup(string $code, float $qty, bool $withOrders = false): array
    {
        $o1 = $this->order($this->warehouseA, 'Maadi');
        $this->line($o1, $this->honey->id, $qty / 2);
        $o2 = $this->order($this->warehouseA, 'Maadi');
        $this->line($o2, $this->honey->id, $qty / 2);

        $this->collect();
        $group = $this->group($this->warehouseA, $code);
        $this->addZone($group['id'], $this->zoneMaadi);

        return [$group, $this->honey->id, [$o1, $o2]];
    }

    /** @param array<string, mixed> $group */
    private function setPrepared(array $group, string $productId, float $qty): \Illuminate\Testing\TestResponse
    {
        $windowId = $this->windowId();

        return $this->actingAs($this->userFor())
            ->putJson(
                self::BASE."/windows/{$windowId}/slots/{$group['id']}/preparation/{$productId}",
                ['prepared_qty' => $qty],
            );
    }

    /**
     * A Preparation wave-level demand row for one product — the OTHER Prepared.
     *
     * Exists only so a test can prove the Group projection does not borrow it. It is
     * never written by production code in this feature.
     */
    private function waveDemandFor(string $productId, float $required, float $prepared): void
    {
        $waveId = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $waveId,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseA->id,
            'wave_number' => 'PREP-LP2-'.substr(uniqid(), -8),
            'planning_date' => now()->toDateString(),
            'starts_at' => now()->copy()->setTime(17, 30),
            'intake_closes_at' => now()->copy()->addDay()->setTime(5, 0),
            'ends_at' => now()->copy()->addDay()->setTime(12, 0),
            'status' => 'preparing',
            'wave_type' => 'engine',
            'created_at' => now(), 'updated_at' => now(),
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
        ]);

        DB::table('wave_product_demand')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseA->id,
            'preparation_wave_id' => $waveId,
            'product_id' => $productId,
            'product_name' => 'wave-level row',
            'product_sku' => 'WAVE-LEVEL',
            'required_qty' => $required,
            'prepared_qty' => $prepared,
            'remaining_qty' => max(0.0, $required - $prepared),
            'orders_count' => 1,
            'completion_pct' => 0,
            'last_calculated_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $group */
    private function storedPrepared(array $group, string $productId): float
    {
        return (float) DB::table('distribution_group_product_preparation')
            ->where('virtual_slot_id', $group['id'])
            ->where('product_id', $productId)
            ->value('prepared_qty');
    }

    /** @param array<string, mixed> $group */
    private function preparationRowCount(array $group): int
    {
        return (int) DB::table('distribution_group_product_preparation')
            ->where('virtual_slot_id', $group['id'])
            ->count();
    }

    /**
     * Set an Order's status directly, the way this suite already does for the
     * cancelled case: no FulfillmentEngine, no workflow, no reservation — the
     * projection reads `orders.status` and nothing else, so the column IS the
     * fixture. Nothing in LP-1.0 or LP-2 writes an order status in production code.
     */
    private function setStatus(Order $order, string $status): void
    {
        DB::table('orders')->where('id', $order->id)->update(['status' => $status]);
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'LP1-'.substr(uniqid(), -6),
            'name_ar' => $name, 'name_en' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(int $governorate, string $en, string $ar, int $zoneId): void
    {
        $id = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorate,
            'name_ar' => $ar, 'name_en' => $en,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $id)->update(['distribution_zone_id' => $zoneId]);
    }

    private function order(Warehouse $warehouse, string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-LP1-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function line(Order $order, string $productId, float $qty): void
    {
        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => 10,
            'line_total' => $qty * 10,
        ]);
    }

    /**
     * An ACTIVE wave membership carrying a postponement — the second half of the
     * eligibility contract. Every NOT NULL column without a default is supplied.
     */
    private function postpone(Order $order): void
    {
        $waveId = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $waveId,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouseA->id,
            'wave_number' => 'PREP-LP1-'.substr(uniqid(), -8),
            'planning_date' => now()->toDateString(),
            'starts_at' => now()->copy()->setTime(17, 30),
            'intake_closes_at' => now()->copy()->addDay()->setTime(5, 0),
            'ends_at' => now()->copy()->addDay()->setTime(12, 0),
            'status' => 'collecting',
            'wave_type' => 'engine',
            'created_at' => now(), 'updated_at' => now(),
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
        ]);

        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $this->company->id,
            'preparation_wave_id' => $waveId,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_confirmed_at' => now(),
            'added_at' => now(),
            'added_by' => (string) Str::uuid(),
            'released_at' => null,       // active membership
            'postponed_at' => now(),     // out of this cycle
        ]);
    }

    // ── API helpers ──────────────────────────────────────────────────────────

    private function userFor(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function collect(): void
    {
        $this->actingAs($this->userFor())
            ->postJson(self::BASE.'/windows/collect')
            ->assertOk();
    }

    private function windowId(): string
    {
        return (string) $this->actingAs($this->userFor())
            ->getJson(self::BASE.'/windows/current')
            ->assertOk()
            ->json('data.window.id');
    }

    /** @return array<string, mixed> the created Group */
    private function group(Warehouse $warehouse, string $code): array
    {
        // The window id is resolved FIRST: calling it inside the actingAs chain
        // would re-authenticate as a different user mid-request.
        $windowId = $this->windowId();

        return $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $warehouse->id,
                'code' => $code,
            ])->assertStatus(201)->json('data');
    }

    private function addZone(string $groupId, int $zoneId): void
    {
        $windowId = $this->windowId();

        $this->actingAs($this->userFor())
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$groupId}/zones", ['zone_id' => $zoneId])
            ->assertOk();
    }

    /** The raw LP-1 payload, exactly as the frontend receives it. */
    private function rawRequired(string $groupId, Warehouse $warehouse): array
    {
        $windowId = $this->windowId();

        return $this->actingAs($this->userFor())
            ->getJson(self::BASE."/windows/{$windowId}/products?slot_id={$groupId}&warehouse_id={$warehouse->id}")
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, float> product id => required quantity */
    private function requiredProducts(string $groupId, Warehouse $warehouse): array
    {
        $out = [];

        foreach ($this->rawRequired($groupId, $warehouse) as $row) {
            $out[(string) $row['product_id']] = (float) $row['total_quantity'];
        }

        return $out;
    }

    /** @return array<string, int> tables LP-1 must never write to */
    private function rowCounts(): array
    {
        return [
            'distribution_window_orders' => DB::table('distribution_window_orders')->count(),
            'distribution_virtual_slots' => DB::table('distribution_virtual_slots')->count(),
            'distribution_slot_zones' => DB::table('distribution_slot_zones')->count(),
            'loading_sessions' => DB::table('loading_sessions')->count(),
            'loading_tasks' => DB::table('loading_tasks')->count(),
            'prepared_products_pool' => DB::table('prepared_products_pool')->count(),
            'stock_ledger_entries' => DB::table('stock_ledger_entries')->count(),
        ];
    }
}
