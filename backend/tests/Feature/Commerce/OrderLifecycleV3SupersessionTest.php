<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Operations\Preparation\Domain\Models\PreparationSessionPolicy;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDER-LIFECYCLE-V3-SUPERSESSION-001 — E2E matrix (CASES 1–19).
 *
 * ADR-042 (Order FSM V3 Canonical): `new` is removed, `confirmed` is restored,
 * entry status is pick-and-stay, and payment method may not rewrite it.
 *
 * Creation cases go through the REAL surface — route → FormRequest → controller
 * → action — because that is where the lifecycle is actually decided and where
 * three separate defects have already hidden from service-layer coverage.
 */
class OrderLifecycleV3SupersessionTest extends TestCase
{
    use RefreshDatabase;

    private const CREATE = '/api/orders/manual';

    private Company $company;

    private Customer $customer;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->product = Product::factory()->create();
    }

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    /** @return array<string, mixed> */
    private function payload(?string $status = null, array $extra = []): array
    {
        $payload = [
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ];

        if ($status !== null) {
            $payload['status'] = $status;
        }

        return array_merge($payload, $extra);
    }

    /** Creates via HTTP and returns the persisted Order, failing loudly if creation was rejected. */
    private function createOrder(?string $status = null, array $extra = []): Order
    {
        $response = $this->actingAs($this->user())->postJson(self::CREATE, $this->payload($status, $extra));

        self::assertContains(
            $response->status(),
            [200, 201],
            'Order creation failed: '.$response->getContent(),
        );

        $id = $response->json('data.id') ?? $response->json('id');
        self::assertNotNull($id, 'Creation response carried no order id: '.$response->getContent());

        /** @var Order $order */
        $order = Order::query()->findOrFail($id);

        return $order;
    }

    /** Reads the raw stored string, bypassing the enum cast — proves what is really in the column. */
    private function storedStatus(Order $order): string
    {
        return (string) DB::table('orders')->where('id', $order->id)->value('status');
    }

    // ══ CASES 1–3 — entry states are stored exactly as chosen ═════════════════

    public function test_case_1_normal_order_is_created_in_progress(): void
    {
        $order = $this->createOrder(OrderStatus::InProgress->value);

        self::assertSame('in_progress', $this->storedStatus($order));
    }

    public function test_case_2_scheduled_order_is_created_scheduled(): void
    {
        $order = $this->createOrder(OrderStatus::Scheduled->value);

        self::assertSame('scheduled', $this->storedStatus($order));
    }

    public function test_case_3_awaiting_payment_order_is_created_awaiting_payment(): void
    {
        $order = $this->createOrder(OrderStatus::AwaitingPayment->value);

        self::assertSame('awaiting_payment', $this->storedStatus($order));
    }

    // ══ CASES 4–7 — non-canonical values are rejected ═════════════════════════

    /**
     * CASE 4/5/6/7. Each premise is guarded first: the value is asserted absent
     * from the enum before rejection is asserted, so if one were ever restored
     * this test fails loudly instead of enforcing a stale expectation.
     */
    public function test_cases_4_to_7_non_canonical_statuses_are_rejected(): void
    {
        $user = $this->user();
        $canonical = array_column(OrderStatus::cases(), 'value');

        foreach (['new', 'pending', 'processing', 'completed'] as $legacy) {
            self::assertNotContains($legacy, $canonical, "'{$legacy}' must not be canonical under ADR-042.");

            $response = $this->actingAs($user)->postJson(self::CREATE, $this->payload($legacy));

            self::assertSame(422, $response->status(), "'{$legacy}' must be rejected with 422.");
            self::assertArrayHasKey(
                'status',
                (array) $response->json('errors'),
                "'{$legacy}' must be rejected by the STATUS rule specifically.",
            );
        }
    }

    // ══ CASES 8–9 — Confirm ═══════════════════════════════════════════════════

    public function test_case_8_confirm_moves_in_progress_to_confirmed(): void
    {
        $order = $this->createOrder(OrderStatus::InProgress->value);
        self::assertSame('in_progress', $this->storedStatus($order));

        $this->actingAs($this->user())
            ->postJson("/api/fulfillment/orders/{$order->id}/confirm")
            ->assertOk();

        self::assertSame('confirmed', $this->storedStatus($order));
    }

    public function test_case_9_confirm_never_produces_a_legacy_or_removed_status(): void
    {
        $order = $this->createOrder(OrderStatus::InProgress->value);

        $this->actingAs($this->user())
            ->postJson("/api/fulfillment/orders/{$order->id}/confirm")
            ->assertOk();

        $stored = $this->storedStatus($order);

        foreach (['processing', 'pending', 'new', 'in_progress'] as $forbidden) {
            self::assertNotSame($forbidden, $stored, "Confirm must not leave the order at '{$forbidden}'.");
        }

        self::assertSame('confirmed', $stored);
        self::assertNotNull($order->refresh()->confirmed_at, 'Confirm must stamp confirmed_at.');
    }

    // ══ CASE 10 — payment method may not rewrite the chosen entry status ══════

    /**
     * This is the exact scenario PAYMENT_CLEAR_STATUS_PREFERENCE used to break:
     * a payment method is present, so the old code substituted its own PREFERRED
     * status for the operator's choice. ADR-042 §4 prohibits that, and still does.
     *
     * SPLIT BY IMPLEMENTATION-002, and why. This case previously looped `instapay` and
     * `mobile_wallet` through the same assertion as `cod` and passed — but only because
     * `payload()` sets no `channel_id`, and the resolver used to hardcode
     * `channel_id IS NULL => 'none'`. Every proof-required method in this loop was
     * therefore resolving to "no requirement" and testing nothing about proof at all.
     *
     * ADR-042 has always drawn the line this test now draws explicitly:
     *
     *   §4   payment method may not act as a PREFERENCE          -> entry status survives
     *   §3.1 a proof-required method is a BLOCKING CONDITION     -> awaiting_payment, AUDITED
     *
     * Nothing is weakened. The §4 invariant is asserted exactly as before for methods that
     * carry no requirement, and the §3.1 branch additionally proves the override is never
     * silent — which is the condition ADR-042 attaches to permitting it at all.
     */
    public function test_case_10_payment_method_cannot_silently_replace_the_selected_entry_status(): void
    {
        // Requirement 'none' — ADR-042 §4. The operator's choice is final.
        foreach (OrderStatus::entryStatuses() as $entry) {
            $order = $this->createOrder($entry->value, ['payment_method_manual' => 'cod']);

            self::assertSame(
                $entry->value,
                $this->storedStatus($order),
                "Entry status '{$entry->value}' must survive payment method 'cod'.",
            );
        }
    }

    /**
     * ADR-042 §3.1 as amended (Owner decision D1-A) — the one sanctioned override, and the
     * audit trail that is the precondition of permitting it.
     */
    public function test_case_10b_a_proof_required_method_parks_the_order_and_audits_the_override(): void
    {
        foreach (['instapay', 'mobile_wallet'] as $method) {
            foreach ([OrderStatus::InProgress, OrderStatus::Scheduled] as $entry) {
                $order = $this->createOrder($entry->value, ['payment_method_manual' => $method]);

                self::assertSame(
                    OrderStatus::AwaitingPayment->value,
                    $this->storedStatus($order),
                    "'{$method}' requires verified proof, which cannot exist at creation, so '{$entry->value}' must yield.",
                );

                self::assertSame(
                    1,
                    \Modules\Commerce\Orders\Domain\Models\OrderEvent::query()
                        ->where('order_id', $order->id)
                        ->where('event_type', 'entry_status_overridden_by_payment_proof_policy')
                        ->count(),
                    'ADR-042 §3.1 permits the override only because it is audited — never silent.',
                );
            }
        }
    }

    // ══ CASES 11–12 — pre-fulfilment states hold ══════════════════════════════

    public function test_case_11_scheduled_order_remains_scheduled_after_creation(): void
    {
        $order = $this->createOrder(OrderStatus::Scheduled->value, [
            'requested_delivery_date' => now()->addDays(7)->toDateString(),
        ]);

        self::assertSame('scheduled', $this->storedStatus($order));
        self::assertNull($order->refresh()->inventory_reserved_at, 'A scheduled order must not reserve at creation.');
    }

    public function test_case_12_awaiting_payment_order_remains_awaiting_payment_after_creation(): void
    {
        $order = $this->createOrder(OrderStatus::AwaitingPayment->value);

        self::assertSame('awaiting_payment', $this->storedStatus($order));
        self::assertNull($order->refresh()->inventory_reserved_at, 'An unpaid order must not reserve at creation.');
    }

    // ══ CASES 13–17 — downstream eligibility ══════════════════════════════════

    public function test_case_13_preparation_recognises_confirmed_and_in_progress(): void
    {
        $statuses = PreparationSessionPolicy::defaultEligibleStatuses();

        self::assertContains('in_progress', $statuses);
        self::assertContains('confirmed', $statuses);
    }

    public function test_case_14_distribution_recognises_confirmed_and_in_progress(): void
    {
        $statuses = (array) config('distribution.eligible_order_statuses');

        self::assertContains('in_progress', $statuses);
        self::assertContains('confirmed', $statuses);
    }

    public function test_case_15_wave_eligibility_covers_confirmed_and_in_progress(): void
    {
        $statuses = array_map(
            static fn (OrderStatus $s): string => $s->value,
            OrderStatus::fulfilmentEligible(),
        );

        self::assertSame(['in_progress', 'confirmed'], $statuses);
    }

    /** CASES 16–17 — the closed list excludes pre-fulfilment states everywhere. */
    public function test_cases_16_and_17_scheduled_and_awaiting_payment_are_not_fulfilment_eligible(): void
    {
        $sets = [
            'preparation' => PreparationSessionPolicy::defaultEligibleStatuses(),
            'distribution' => (array) config('distribution.eligible_order_statuses'),
            'wave/enum' => array_map(
                static fn (OrderStatus $s): string => $s->value,
                OrderStatus::fulfilmentEligible(),
            ),
        ];

        foreach ($sets as $name => $statuses) {
            self::assertNotContains('scheduled', $statuses, "[{$name}] must not admit scheduled orders.");
            self::assertNotContains('awaiting_payment', $statuses, "[{$name}] must not admit awaiting-payment orders.");
            self::assertNotContains('new', $statuses, "[{$name}] must not reference the removed `new` status.");
        }
    }

    // ══ CASES 18–19 — the normalisation migration ═════════════════════════════

    /**
     * Inserts a historical `new` row with raw SQL — the enum cast can no longer
     * produce one — then runs the migration and proves the row is normalised.
     *
     * Running the migration class directly is deliberate: it is the artefact that
     * must work on a production database that still holds `new`, and RefreshDatabase
     * has already applied it to an empty table, which proves nothing.
     */
    public function test_cases_18_and_19_migration_normalises_historical_new_rows_to_in_progress(): void
    {
        $order = $this->createOrder(OrderStatus::InProgress->value);

        // Reintroduce the historical value beneath the enum.
        DB::table('orders')->where('id', $order->id)->update(['status' => 'new']);
        self::assertSame(1, DB::table('orders')->where('status', 'new')->count(), 'Precondition: one `new` row exists.');

        $migration = require base_path(
            'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_08_13_100000_supersede_order_lifecycle_v3_canonical.php',
        );
        $migration->up();

        // CASE 18 — the row is normalised, not deleted.
        self::assertSame('in_progress', $this->storedStatus($order));

        // CASE 19 — the invariant the enum removal depends on.
        self::assertSame(
            0,
            DB::table('orders')->where('status', 'new')->count(),
            'No `new` row may remain once the migration has run.',
        );

        // Idempotent: a second run changes nothing and throws nothing.
        $migration->up();
        self::assertSame(0, DB::table('orders')->where('status', 'new')->count());
        self::assertSame('in_progress', $this->storedStatus($order));
    }

    /**
     * The hydration guarantee that makes the enum change safe: every status value
     * present in the database must map to a canonical case.
     */
    public function test_every_stored_status_hydrates_after_normalisation(): void
    {
        $this->createOrder(OrderStatus::InProgress->value);
        $this->createOrder(OrderStatus::Scheduled->value);
        $this->createOrder(OrderStatus::AwaitingPayment->value);

        $distinct = DB::table('orders')->distinct()->pluck('status');

        foreach ($distinct as $value) {
            self::assertNotNull(
                OrderStatus::tryFrom((string) $value),
                "Stored status '{$value}' cannot be hydrated by OrderStatus.",
            );
        }
    }

    /**
     * The WooCommerce translator is a second, string-literal copy of the status
     * vocabulary. It has silently broken twice for exactly this reason — an enum
     * change leaving its right-hand side pointing at a value that no longer exists.
     * Asserting it here makes the third time a test failure instead of an outage.
     */
    public function test_woocommerce_status_translator_maps_only_to_canonical_statuses(): void
    {
        $translator = app(\Modules\Commerce\Synchronization\Application\Services\WooCommerceOrderStatusTranslator::class);

        foreach (['pending', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded', 'failed'] as $wc) {
            $translated = $translator->translate($wc);

            self::assertNotNull(
                $translated,
                "WooCommerce status '{$wc}' must translate to a canonical OrderStatus, not null.",
            );
        }

        // An unmapped WC status must return null so the importer can fall back,
        // rather than yielding a non-canonical string that OrderStatus::from() rejects.
        self::assertNull($translator->translate('trash'));
    }

    /** The column default must be canonical — it was `pending`, which no case accepts. */
    public function test_orders_status_column_default_is_canonical(): void
    {
        $default = DB::table('orders')->getConnection()
            ->getSchemaBuilder()
            ->getColumns('orders');

        $status = collect($default)->firstWhere('name', 'status');
        self::assertNotNull($status, 'orders.status column not found.');

        $value = trim((string) ($status['default'] ?? ''), "'\"");

        self::assertNotSame('pending', $value, 'orders.status must not default to the non-canonical `pending`.');
        self::assertNotNull(
            OrderStatus::tryFrom($value),
            "orders.status default '{$value}' is not a canonical status.",
        );
    }
}
