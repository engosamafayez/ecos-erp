<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Commerce\Synchronization\Application\Jobs\ProcessOrderWebhookJob;
use Modules\Commerce\Synchronization\Application\Services\WooRefundApplicationService;
use Modules\Finance\Fiscal\Domain\Models\FiscalPeriod;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Integration\Domain\Services\CommercialAccountingService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Receivables\Domain\Enums\CustomerDocumentType;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Operations\Fulfillment\Domain\Models\CustomerReturn;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-WOO-01-VERIFICATION-REMEDIATION-CHECKPOINT-043-R1.
 *
 * Financial-only, per WooRefundApplicationService's own class docblock: a
 * WooCommerce refund's line_items/quantities are a commercial/accounting
 * allocation, not physical-return evidence, so no test here exercises or
 * expects a ReturnOrderWorkflow/CustomerReturn/Inventory side effect from a
 * Woo refund — cases §5/§9/§10 of the checkpoint are proven by their ABSENCE
 * (see test_refund_with_goods_shaped_payload_never_invokes_physical_return).
 *
 * Does not re-assert the pre-existing, already-covered order/status/webhook
 * behavior (translator mapping, HMAC verification, duplicate-webhook
 * suppression, historical-import isolation) — those remain covered by
 * EcosOrderStatusToWooTranslatorTest and OrdersSyncControlsAndHistoricalImportTest,
 * both left unmodified.
 */
final class WooCommerceRefundIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private string $companyId;

    private Brand $brand;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->companyId = (string) $this->company->id;
        $this->openPeriodForCompany($this->companyId);
        $this->controlAccount($this->companyId, 'ar', AccountType::Asset);
        $this->seedRole($this->companyId, 'sales_revenue', AccountType::Revenue);

        $this->brand = Brand::factory()->create(['company_id' => $this->companyId]);
        $this->channel = Channel::factory()->create([
            'brand_id' => $this->brand->id,
            'store_url' => 'https://shop.test',
        ]);
        ChannelCredential::query()->create([
            'channel_id' => $this->channel->id,
            'consumer_key' => 'ck_test',
            'consumer_secret' => 'cs_test',
        ]);
    }

    // ── 1. Full financial refund ───────────────────────────────────────────────

    public function test_full_financial_refund_posts_a_credit_note_for_the_full_amount(): void
    {
        [$order] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered);

        $outcome = app(WooRefundApplicationService::class)->applyRefund(
            $this->channel, $order, $this->wooRefund('10', 1000.0),
        );

        self::assertSame('posted', $outcome->financialStatus);
        self::assertSame(1000.0, $outcome->amount);
        self::assertSame(OrderStatus::Delivered, $order->fresh()->status, 'a financial-only refund must not change order status');

        $creditNote = CustomerInvoice::query()->where('uuid', $outcome->creditNoteUuid)->firstOrFail();
        self::assertTrue($creditNote->isPosted());
        self::assertSame(CustomerDocumentType::CreditNote, $creditNote->document_type);
    }

    // ── 2 & 3. Partial refund, then a second incremental partial refund ───────

    public function test_partial_refunds_accumulate_to_the_correct_cumulative_total(): void
    {
        [$order] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered);
        $service = app(WooRefundApplicationService::class);

        $first = $service->applyRefund($this->channel, $order, $this->wooRefund('21', 200.0));
        self::assertSame('posted', $first->financialStatus);
        self::assertSame(200.0, $first->amount);

        $second = $service->applyRefund($this->channel, $order, $this->wooRefund('22', 100.0));
        self::assertSame('posted', $second->financialStatus);
        self::assertSame(100.0, $second->amount);

        $totalRefunded = (float) CustomerInvoice::query()
            ->where('company_id', $this->companyId)
            ->where('document_type', CustomerDocumentType::CreditNote->value)
            ->where('source_type', 'woo_refund')
            ->where('source_id', 'like', "{$this->channel->id}:{$order->external_order_id}:%")
            ->sum('total');

        self::assertSame(300.0, round($totalRefunded, 4), 'canonical result must be exactly 300, not 100/200/1000 twice');
        self::assertSame(2, CustomerInvoice::query()->where('document_type', CustomerDocumentType::CreditNote->value)->count());
    }

    // ── 4. Duplicate replay is idempotent ──────────────────────────────────────

    public function test_replaying_the_same_refund_event_produces_no_additional_financial_effect(): void
    {
        [$order] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered);
        $service = app(WooRefundApplicationService::class);

        $first = $service->applyRefund($this->channel, $order, $this->wooRefund('30', 400.0));
        $second = $service->applyRefund($this->channel, $order, $this->wooRefund('30', 400.0));

        self::assertSame('posted', $first->financialStatus);
        self::assertSame('idempotent_replay', $second->financialStatus);
        self::assertSame($first->creditNoteUuid, $second->creditNoteUuid);
        self::assertSame(
            1,
            CustomerInvoice::query()->where('document_type', CustomerDocumentType::CreditNote->value)->count(),
            'same refund event twice must produce exactly one financial effect',
        );
    }

    // ── 5. Cumulative Woo refund array replay across separate webhook deliveries ──

    public function test_cumulative_refund_array_replay_across_separate_webhook_deliveries(): void
    {
        [$order] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered);

        // Delivery 1: only refund A.
        $this->deliverOrderWebhook($order, ['refunds' => [$this->wooRefund('A', 200.0)]]);
        self::assertSame(200.0, round($this->totalCreditNotes(), 4));
        self::assertSame(1, $this->creditNoteCount());

        // Delivery 2 (later): Woo resends A again (already processed) plus new B.
        $payload2 = ['refunds' => [$this->wooRefund('A', 200.0), $this->wooRefund('B', 100.0)]];
        $this->deliverOrderWebhook($order, $payload2);
        self::assertSame(2, $this->creditNoteCount(), 'A must be a no-op replay; only B creates a new row');
        self::assertSame(300.0, round($this->totalCreditNotes(), 4));

        // Delivery 3: exact replay of delivery 2's payload — zero additional effect.
        $this->deliverOrderWebhook($order, $payload2);
        self::assertSame(2, $this->creditNoteCount());
        self::assertSame(300.0, round($this->totalCreditNotes(), 4));
    }

    // ── 6. Over-refund protection ───────────────────────────────────────────────

    public function test_over_refund_is_rejected_without_posting_or_clamping(): void
    {
        [$order] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered);
        $service = app(WooRefundApplicationService::class);

        $service->applyRefund($this->channel, $order, $this->wooRefund('40', 800.0));
        $rejected = $service->applyRefund($this->channel, $order, $this->wooRefund('41', 300.0)); // only 200 remains

        self::assertSame('over_refund_rejected', $rejected->financialStatus);
        self::assertNull($rejected->creditNoteUuid);
        self::assertSame(800.0, round($this->totalCreditNotes(), 4), 'the rejected amount must never be silently clamped and posted');
    }

    // ── 7. Financial-only refund does NOT create a physical return ────────────
    // ── 9/10 (N/A by architecture decision — see class docblock) combined here ──

    public function test_refund_with_goods_shaped_payload_never_invokes_physical_return(): void
    {
        // A refund reason/shape that LOOKS like a goods return must still never touch
        // ReturnOrderWorkflow/CustomerReturn/Inventory or Order.status — WooCommerce's
        // standard payload carries no trustworthy warehouse-receipt evidence at all
        // (checkpoint §5). Order is deliberately OutForDelivery — the one state
        // ReturnOrderWorkflow's guard would otherwise accept — to prove this isn't
        // merely a state-guard rejection but a deliberate absence of the call itself.
        [$order] = $this->makeOrderWithInvoiceAndLine(500.0, OrderStatus::OutForDelivery);

        $outcome = app(WooRefundApplicationService::class)->applyRefund(
            $this->channel, $order, $this->wooRefund('60', 500.0, 'customer returned the item'),
        );

        self::assertSame('posted', $outcome->financialStatus);
        self::assertSame('not_evaluated_no_reliable_evidence', $outcome->physicalReturnStatus);
        self::assertSame(OrderStatus::OutForDelivery, $order->fresh()->status);
        self::assertSame(0, CustomerReturn::query()->where('order_id', $order->id)->count());
    }

    // ── 8. Finance failure does not leave half-applied Commerce state ────────

    public function test_finance_posting_failure_propagates_and_does_not_silently_partially_apply(): void
    {
        // The ORIGINAL invoice must post successfully (an order genuinely delivered and
        // recognised) so a real invoice exists to refund against; ONLY the REFUND's own
        // Credit Note posting must fail — proven by disabling the AR control account
        // (ControlAccountResolver re-resolves it fresh on every postDocument() call, per
        // its own source) strictly AFTER the setup invoice already posted.
        [$order] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered);

        Account::query()
            ->where('company_id', $this->companyId)
            ->where('is_control', true)
            ->where('control_subledger', 'ar')
            ->update(['is_active' => false]);

        $this->expectException(\Modules\Finance\Ledger\Domain\Exceptions\FinanceException::class);

        try {
            app(WooRefundApplicationService::class)->applyRefund($this->channel, $order, $this->wooRefund('80', 1000.0));
        } finally {
            // Not "zero rows": createDocument() and postDocument() are deliberately two
            // separate calls with separate transaction boundaries (see the service's own
            // class docblock) — a failure between them leaves a DRAFT Credit Note, which
            // is the durable, visible, re-postable recovery state this design commits to,
            // not a silently half-applied one. The invariant that actually matters is that
            // nothing GL-affecting happened: no row may be Posted, and none may count
            // toward the refundable-ceiling sum a subsequent attempt would compute.
            $rows = CustomerInvoice::query()->where('document_type', CustomerDocumentType::CreditNote->value)->get();
            self::assertCount(1, $rows, 'exactly the one draft Credit Note from this attempt, no duplicate, no silent extra row');
            self::assertFalse($rows->first()->isPosted(), 'a failed posting attempt must never leave a Posted (GL-affecting) row behind');
        }

        // The recovery half of the same scenario: once the operator fixes the underlying
        // issue, a retry of the SAME refund event must actually complete — not be
        // permanently reported as "already applied" just because a draft row exists.
        Account::query()
            ->where('company_id', $this->companyId)
            ->where('is_control', true)
            ->where('control_subledger', 'ar')
            ->update(['is_active' => true]);

        $retry = app(WooRefundApplicationService::class)->applyRefund($this->channel, $order, $this->wooRefund('80', 1000.0));

        self::assertSame('posted', $retry->financialStatus);
        self::assertSame(1, CustomerInvoice::query()->where('document_type', CustomerDocumentType::CreditNote->value)->where('status', 'posted')->count());
    }

    // ── 9. Pre-delivery refund / missing financial backing (checkpoint §4) ────

    public function test_pre_delivery_refund_requires_reconciliation_without_fabricating_anything(): void
    {
        // Woo order exists, but Commerce revenue has NOT been recognized (no delivery
        // event has fired, so CommercialAccountingService never created an invoice) —
        // exactly the "pre-delivery refund" case checkpoint §4 requires.
        $customerId = $this->makeCustomerId();
        $order = $this->makeOrder([
            'channel_id' => $this->channel->id,
            'company_id' => $this->companyId,
            'customer_id' => $customerId,
            'external_order_id' => 'NOINV-1',
            'status' => OrderStatus::InProgress->value, // never delivered
        ]);

        $outcome = app(WooRefundApplicationService::class)->applyRefund(
            $this->channel, $order, $this->wooRefund('90', 500.0),
        );

        self::assertSame('reconciliation_required', $outcome->financialStatus);
        self::assertNull($outcome->creditNoteUuid);
        self::assertSame(0, $this->creditNoteCount(), 'no invoice, credit note, or journal entry may be fabricated');
        self::assertSame(0, CustomerReturn::query()->where('order_id', $order->id)->count(), 'no physical-return inference either');
        self::assertSame(OrderStatus::InProgress, $order->fresh()->status);
    }

    // ── 10. Refunded status cannot produce a second (double) path ──────────────

    public function test_refunded_status_produces_exactly_one_effect_never_a_second_status_transition(): void
    {
        [$order] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered);

        // Woo sets the order's own status to 'refunded' only for a FULL refund — the
        // translator maps this to OrderStatus::Returned, which the generic match()
        // would otherwise try to reach via CancelOrderWorkflow/etc. It must not: this
        // event must produce exactly the financial effect, nothing from the
        // status-transition branch, and no direct Order.status write.
        $this->deliverOrderWebhook($order, [
            'status' => 'refunded',
            'refunds' => [$this->wooRefund('300', 1000.0)],
        ]);

        self::assertSame(1, $this->creditNoteCount());
        self::assertSame(1000.0, round($this->totalCreditNotes(), 4));
        self::assertSame(
            OrderStatus::Delivered,
            $order->fresh()->status,
            'the generic status-transition match() must never also fire for a refunded event',
        );
    }

    // ── 11. Currency mismatch fails explicitly (checkpoint §15) ────────────────

    public function test_currency_mismatch_fails_explicitly_without_conversion(): void
    {
        [$order] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered); // invoice currency is EGP (default)

        $outcome = app(WooRefundApplicationService::class)->applyRefund(
            $this->channel, $order, ['id' => '500', 'total' => 100.0, 'currency' => 'USD'],
        );

        self::assertSame('currency_mismatch', $outcome->financialStatus);
        self::assertSame(0, $this->creditNoteCount());
    }

    // ── 12. Tenant/company isolation ───────────────────────────────────────────

    public function test_refund_against_one_company_never_touches_another_companys_invoice(): void
    {
        [$orderA] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered);

        $companyBId = (string) Company::factory()->create()->id;
        $this->openPeriodForCompany($companyBId);
        $this->controlAccount($companyBId, 'ar', AccountType::Asset);
        $this->seedRole($companyBId, 'sales_revenue', AccountType::Revenue);
        $brandB = Brand::factory()->create(['company_id' => $companyBId]);
        $channelB = Channel::factory()->create(['brand_id' => $brandB->id, 'store_url' => 'https://shop-b.test']);
        ChannelCredential::query()->create(['channel_id' => $channelB->id, 'consumer_key' => 'ck_b', 'consumer_secret' => 'cs_b']);
        $customerB = $this->makeCustomerId();
        $orderB = $this->makeOrder([
            'channel_id' => $channelB->id,
            'company_id' => $companyBId,
            'customer_id' => $customerB,
            'external_order_id' => 'B-1',
            'status' => OrderStatus::Delivered->value,
        ]);
        app(CommercialAccountingService::class)->recognizeRevenue(
            $companyBId, $orderB->id, $orderB->order_number, $customerB, 1000.0, 0.0, now(), null,
        );

        app(WooRefundApplicationService::class)->applyRefund($this->channel, $orderA, $this->wooRefund('100', 500.0));

        self::assertSame(
            0,
            CustomerInvoice::query()->where('company_id', $companyBId)->where('document_type', CustomerDocumentType::CreditNote->value)->count(),
            'Company B must be entirely unaffected by a refund against Company A',
        );
    }

    // ── 13. Another Woo channel cannot replay the same external ID ────────────

    public function test_two_channels_sharing_an_external_order_id_do_not_collide(): void
    {
        [$orderChannel1] = $this->makeOrderWithInvoiceAndLine(1000.0, OrderStatus::Delivered, externalOrderId: 'SHARED-1');

        $channel2 = Channel::factory()->create(['brand_id' => $this->brand->id, 'store_url' => 'https://shop2.test']);
        ChannelCredential::query()->create(['channel_id' => $channel2->id, 'consumer_key' => 'ck2', 'consumer_secret' => 'cs2']);
        $customer2 = $this->makeCustomerId();
        $orderChannel2 = $this->makeOrder([
            'channel_id' => $channel2->id,
            'company_id' => $this->companyId,
            'customer_id' => $customer2,
            'external_order_id' => 'SHARED-1', // same external id, different channel — allowed
            'status' => OrderStatus::Delivered->value,
        ]);
        app(CommercialAccountingService::class)->recognizeRevenue(
            $this->companyId, $orderChannel2->id, $orderChannel2->order_number, $customer2, 1000.0, 0.0, now(), null,
        );

        $service = app(WooRefundApplicationService::class);
        $r1 = $service->applyRefund($this->channel, $orderChannel1, $this->wooRefund('200', 300.0));
        $r2 = $service->applyRefund($channel2, $orderChannel2, $this->wooRefund('200', 300.0));

        self::assertSame('posted', $r1->financialStatus);
        self::assertSame('posted', $r2->financialStatus);
        self::assertNotSame($r1->creditNoteUuid, $r2->creditNoteUuid);
        self::assertSame(2, $this->creditNoteCount());
    }

    // ═══ HELPERS ═══════════════════════════════════════════════════════════════

    /**
     * Order has no HasFactory trait (verified in current source), so every order
     * fixture goes through this helper rather than Order::factory(), supplying the
     * three columns the orders table requires with no default: customer_id (FK),
     * order_number (unique), order_date.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeOrder(array $overrides): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TEST-'.Str::random(12),
            'order_date' => now()->toDateString(),
        ], $overrides));
    }

    /** A real Customer row — orders.customer_id is FK-constrained, so a bare Str::uuid() 404s. */
    private function makeCustomerId(): string
    {
        return (string) \Modules\Crm\Customers\Domain\Models\Customer::create([
            'code' => 'CUST-'.Str::random(10),
            'name' => 'Test Customer',
        ])->id;
    }

    /** @return array{0: Order, 1: OrderLine} */
    private function makeOrderWithInvoiceAndLine(
        float $total,
        OrderStatus $status,
        float $quantity = 1.0,
        ?string $externalOrderId = null,
    ): array {
        $customerId = $this->makeCustomerId();
        $product = Product::factory()->create(['sku' => 'SKU-1']);

        $order = $this->makeOrder([
            'channel_id' => $this->channel->id,
            'company_id' => $this->companyId,
            'customer_id' => $customerId,
            'external_order_id' => $externalOrderId ?? ('EXT-'.Str::random(8)),
            'status' => $status->value,
            'total' => $total,
        ]);

        $line = OrderLine::create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => $total / max($quantity, 1),
            'line_total' => $total,
        ]);

        app(CommercialAccountingService::class)->recognizeRevenue(
            companyId: $this->companyId,
            orderId: $order->id,
            orderNumber: $order->order_number,
            customerId: $customerId,
            grossRevenue: $total,
            taxTotal: 0.0,
            recognizedAt: now(),
            actorId: null,
        );

        return [$order->fresh(), $line];
    }

    /** Runs ProcessOrderWebhookJob::handle() synchronously, in-process — no queue involved. */
    private function deliverOrderWebhook(Order $order, array $payloadOverrides): void
    {
        $payload = array_merge([
            'id' => $order->external_order_id,
            'status' => 'processing',
            'currency' => 'EGP',
        ], $payloadOverrides);

        $job = new ProcessOrderWebhookJob($this->channel, $payload, 'order.updated');
        app()->call([$job, 'handle']);
    }

    private function creditNoteCount(): int
    {
        return CustomerInvoice::query()->where('document_type', CustomerDocumentType::CreditNote->value)->count();
    }

    private function totalCreditNotes(): float
    {
        return (float) CustomerInvoice::query()->where('document_type', CustomerDocumentType::CreditNote->value)->sum('total');
    }

    /** @return array{id: string, reason: string, total: float} */
    private function wooRefund(string $id, float $total, string $reason = ''): array
    {
        return ['id' => $id, 'reason' => $reason, 'total' => $total];
    }

    private function seedRole(string $companyId, string $role, AccountType $type): Account
    {
        $account = app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => strtoupper(substr($role, 0, 3)).'-'.$this->suffix(),
            'name' => ucfirst(str_replace('_', ' ', $role)),
            'account_type' => $type,
            'is_postable' => true,
        ]);

        DB::table('finance_account_roles')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'role' => $role,
            'account_id' => $account->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $account;
    }

    private function controlAccount(string $companyId, string $subledger, AccountType $type): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => strtoupper($subledger).'-CTRL-'.$this->suffix(),
            'name' => strtoupper($subledger).' control',
            'account_type' => $type,
            'is_postable' => true,
            'is_control' => true,
            'control_subledger' => $subledger,
        ]);
    }

    private function suffix(): string
    {
        return substr(md5(uniqid('', true)), 0, 8);
    }

    private function openPeriodForCompany(string $companyId): FiscalPeriod
    {
        $start = now()->subMonths(3)->startOfMonth();
        $year = app(FiscalCalendarService::class)->createYear(
            $companyId, 'FY-'.$this->suffix(), $start, $start->copy()->addMonths(11)->endOfMonth(),
        );

        foreach ($year->periods as $period) {
            if ($period->status->value !== 'open') {
                app(FiscalCalendarService::class)->openPeriod($period);
            }
        }

        return $year->periods()->where('period_number', 1)->firstOrFail();
    }
}
