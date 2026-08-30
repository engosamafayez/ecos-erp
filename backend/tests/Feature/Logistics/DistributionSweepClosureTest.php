<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Models\DistributionGroupTemplate;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindow;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DailyGroupLifecycleService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Preparation\Domain\Events\WavePreparationStarted;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DISTRIBUTION-WAVE-GROUP-SWEEP-CLOSURE-FIX-001
 *
 * ┌─ WHAT THIS PINS ─────────────────────────────────────────────────────────┐
 * │ §2  The sweep is no longer silent: its tally reaches a log, and eligible  │
 * │     work in a Zone NO active Template covers is NAMED rather than being   │
 * │     indistinguishable from "no work at all".                              │
 * │ §5  Sweeping twice creates once and reuses after.                         │
 * │ §4  A MANUAL Group holding a Zone an active Template also covers: what    │
 * │     the sweep ACTUALLY does — it creates a zone-less duplicate.           │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The fixture shape mirrors live DEV deliberately: operator-created Groups carry
 * `distribution_group_template_id = NULL`, which is what puts them outside the
 * `(wave, template)` unique index and outside `findGroup()`'s reach.
 */
final class DistributionSweepClosureTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $maadi;

    private int $obour;

    private DailyGroupLifecycleService $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->maadi = $this->zone('Maadi');
        $this->obour = $this->zone('Obour');
        $this->city($governorate, 'Maadi', 'المعادي', $this->maadi);
        $this->city($governorate, 'Obour City', 'مدينة العبور', $this->obour);

        $this->lifecycle = app(DailyGroupLifecycleService::class);
    }

    // ── §2. The sweep reports itself ─────────────────────────────────────────

    public function test_the_sweep_logs_its_tally_when_it_creates_a_group(): void
    {
        $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());

        Log::spy();

        $this->startWaveViaEngine($waveId, $this->day());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($waveId): bool {
                return $message === 'distribution.wave_sweep'
                    && $context['wave_id'] === $waveId
                    && $context['created'] === 1
                    && $context['skipped'] === 0;
            })
            ->once();
    }

    /**
     * THE FACT THAT MADE THE WAVE-009 INCIDENT A FORENSIC.
     *
     * A Zone attached to no Template is unreachable: its Orders can never receive a
     * Group and the sweep declines in silence. "No work" and "work no Template can
     * reach" must never render the same way.
     */
    public function test_eligible_work_in_an_uncovered_zone_is_named_not_silently_skipped(): void
    {
        // A Template that covers Maadi only — Obour is covered by nothing.
        $this->template('Morning', [$this->maadi]);
        $this->order('Obour City');
        $this->collect();

        $waveId = $this->wave($this->day());

        Log::spy();

        $this->startWaveViaEngine($waveId, $this->day());

        self::assertSame(0, VirtualCapacitySlot::query()->count(), 'no Group could be created');

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                if ($message !== 'distribution.wave_sweep') {
                    return false;
                }

                $uncovered = $context['uncovered_zones'] ?? [];
                $skipped = $context['skipped_templates'] ?? [];

                return $context['created'] === 0
                    && $context['skipped'] === 1
                    && count($uncovered) === 1
                    && (int) $uncovered[0]['zone_id'] === $this->obour
                    && (int) $uncovered[0]['eligible'] === 1
                    && $skipped[0]['reason'] === DailyGroupLifecycleService::SKIP_NO_ELIGIBLE_WORK;
            })
            ->once();
    }

    // ── §5. Idempotency ──────────────────────────────────────────────────────

    public function test_a_second_sweep_creates_nothing_and_reuses_instead(): void
    {
        $template = $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        $waveId = $this->wave($this->day());

        $this->startWaveViaEngine($waveId, $this->day());
        $first = $this->lifecycle->findGroup($waveId, (string) $template->id);
        self::assertNotNull($first, 'the first sweep created the Group');

        Log::spy();

        $this->startWaveViaEngine($waveId, $this->day());

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'distribution.wave_sweep'
                    && $context['created'] === 0
                    && $context['reused'] === 1;
            })
            ->once();

        self::assertSame(1, VirtualCapacitySlot::query()->count(), 'no duplicate Group');
        self::assertSame(
            $first->id,
            $this->lifecycle->findGroup($waveId, (string) $template->id)?->id,
            'the same Group is returned',
        );
    }

    // ── §4. Manual Group / Template zone overlap ─────────────────────────────

    /**
     * ┌─ THE PROVEN DEFECT ──────────────────────────────────────────────────┐
     * │ `distribution_slot_zones` is UNIQUE on (window, warehouse, zone), so a │
     * │ Zone belongs to at most one Group per window. When an operator Group    │
     * │ already holds a Zone an active Template covers, the sweep does NOT      │
     * │ fail — it creates a SECOND Group for the same scope, and that Group     │
     * │ ends up carrying NO ZONES.                                             │
     * │                                                                        │
     * │ The collector routes Orders BY ZONE, so a zone-less Group can never     │
     * │ receive one. It is a permanent empty shell on the board, created in     │
     * │ silence — the duplicate-for-the-same-scope this task set out to prevent.│
     * └──────────────────────────────────────────────────────────────────────┘
     *
     * Live DEV is one edit away: four of five active Templates cover a Zone an
     * operator Group already holds; only zero eligibility is delaying it.
     */
    public function test_a_group_created_over_an_owned_zone_is_reported_as_a_zone_conflict(): void
    {
        $this->template('Morning', [$this->maadi]);
        $this->order('Maadi');
        $this->collect();

        // An operator Group in the same window+warehouse, already holding Maadi.
        // Template id NULL — exactly the live shape of DG-001..DG-005.
        $operator = $this->manualGroupHoldingZone($this->maadi);

        $waveId = $this->wave($this->day());

        Log::spy();

        $thrown = null;

        try {
            $this->startWaveViaEngine($waveId, $this->day());
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNull(
            $thrown,
            'the sweep does not throw; got '.($thrown === null ? '' : $thrown::class.': '.$thrown->getMessage()),
        );

        /*
         * THE BEHAVIOUR IS DELIBERATELY UNCHANGED. A second Group IS created for the same
         * scope and it TAKES THE ZONE from the Group that held it, leaving that one empty.
         * For a wave rotation this is correct — the new Wave's Group should own the Zone.
         * Against an OPERATOR Group it silently empties the operator's work. Refusing
         * here would look correct but breaks the certified multi-Wave rule that each Wave
         * gets its own Group while the previous Wave's Group still holds the Zones
         * (`test_three_waves_on_one_day_each_get_their_own_group`). Telling "next Wave's
         * Group" apart from "duplicate beside an operator Group" needs an ownership rule
         * this codebase does not have, so this pins the status quo AND its visibility.
         */
        $groups = VirtualCapacitySlot::query()->get();
        self::assertCount(2, $groups, 'observed: '.$groups->pluck('code')->implode(', '));

        $sweepGroup = $groups->firstWhere('distribution_group_template_id', '!=', null);
        self::assertNotNull($sweepGroup, 'the sweep created its own Group');

        // THE ZONE IS MOVED, NOT DUPLICATED. `assignZoneToSlot()` re-points the existing
        // (window, warehouse, zone) row at the new Group, which is why the unique index
        // never fires and nothing throws. The new Group gains the Zone...
        self::assertSame(
            1,
            DB::table('distribution_slot_zones')->where('virtual_slot_id', $sweepGroup->id)->count(),
            'the sweep Group took the zone',
        );
        self::assertSame(
            $sweepGroup->id,
            DB::table('distribution_slot_zones')->where('distribution_zone_id', $this->maadi)->value('virtual_slot_id'),
            'the zone now belongs to the sweep Group',
        );

        // ...and the OPERATOR Group is silently left with none. That is the real cost:
        // an operator's Group is emptied without warning, and the Orders that were routed
        // to it by Zone now route elsewhere.
        self::assertSame(
            0,
            DB::table('distribution_slot_zones')->where('virtual_slot_id', $operator->id)->count(),
            'the operator Group was stripped of its zone',
        );

        // WHAT THIS TASK ADDS: the situation is now NAMED instead of silent.
        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context): bool {
                if ($message !== 'distribution.wave_sweep') {
                    return false;
                }

                $conflicts = $context['zone_conflicts'] ?? [];

                return $context['created'] === 1
                    && count($conflicts) === 1
                    && ($conflicts[0]['reason'] ?? null) === DailyGroupLifecycleService::ZONES_ALREADY_OWNED;
            })
            ->once();
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    private function day(): string
    {
        return now()->toDateString();
    }

    /** Dispatch the REAL automated wave-start event so the listener wiring is what runs. */
    private function startWaveViaEngine(string $waveId, string $date): void
    {
        event(new WavePreparationStarted(
            waveId: $waveId,
            waveNumber: 'W-'.substr($waveId, 0, 6),
            companyId: (string) $this->company->id,
            warehouseId: (string) $this->warehouse->id,
            planningDate: $date,
            ordersCount: 0,
            orderIds: [],
            startedBy: 'system',
            startedAt: now()->toIso8601String(),
        ));
    }

    /** An operator-created Group holding one Zone: no template, mirroring live DEV. */
    private function manualGroupHoldingZone(int $zoneId): VirtualCapacitySlot
    {
        $window = $this->window($this->day());

        $group = VirtualCapacitySlot::query()->create([
            'company_id' => $this->company->id,
            'distribution_window_id' => $window->id,
            'warehouse_id' => $this->warehouse->id,
            'code' => 'OPS-'.substr(uniqid(), -5),
        ]);

        // No company_id column here, and `id` is an auto-increment bigint — the
        // uniqueness that matters is (window, warehouse, zone).
        DB::table('distribution_slot_zones')->insert([
            'distribution_window_id' => $window->id,
            'warehouse_id' => $this->warehouse->id,
            'distribution_zone_id' => $zoneId,
            'virtual_slot_id' => $group->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $group->refresh();
    }

    private function wave(string $date, string $status = 'collecting'): string
    {
        $id = (string) Str::uuid();

        DB::table('preparation_waves')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'W-'.substr(uniqid(), -8),
            'planning_date' => $date,
            'status' => $status,
            'wave_type' => 'engine',
            'starts_at' => $date.' 00:00:00',
            'ends_at' => $date.' 23:59:59',
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    private function window(string $date): DistributionWindow
    {
        $existing = DistributionWindow::query()
            ->where('company_id', $this->company->id)
            ->whereDate('window_date', $date)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $id = (string) Str::uuid();

        DB::table('distribution_windows')->insert([
            'id' => $id,
            'company_id' => $this->company->id,
            'window_date' => $date,
            'status' => 'open',
            'opens_at' => $date.' 00:00:00',
            'closes_at' => $date.' 23:59:59',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DistributionWindow::query()->findOrFail($id);
    }

    /** @param  list<int>  $zoneIds */
    private function template(string $name, array $zoneIds): DistributionGroupTemplate
    {
        $id = (string) $this->actingAs($this->user())
            ->postJson(self::BASE.'/group-templates', ['name' => $name, 'zone_ids' => $zoneIds])
            ->assertStatus(201)->json('data.id');

        return DistributionGroupTemplate::query()->findOrFail($id);
    }

    private function collect(): void
    {
        $this->actingAs($this->user())->postJson(self::BASE.'/windows/collect')->assertOk();
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'SC-'.substr(uniqid(), -6),
            'name_ar' => $name.'-'.uniqid(), 'name_en' => $name,
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

    private function order(string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-SC-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100, 'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }
}
