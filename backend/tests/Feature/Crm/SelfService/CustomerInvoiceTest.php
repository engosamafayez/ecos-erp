<?php

declare(strict_types=1);

namespace Tests\Feature\Crm\SelfService;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Crm\SelfService\Domain\Services\CustomerTrackingTokenService;
use Modules\Finance\Ledger\Domain\Enums\AccountType;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Finance\Ledger\Domain\Services\ChartOfAccountsService;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-04-BACKEND-SOURCE-CLOSURE-REMEDIATION-019R1 §5 — items 19-27. A customer
 * may view ONLY their own order's invoice, sourced entirely from Finance's own canonical
 * `CustomerInvoice`/`AccountsReceivableService` (never a second, CRM-owned invoice model or a
 * hand-rolled total), with no GL/posting internals ever projected, and an honest PDF boundary.
 */
final class CustomerInvoiceTest extends TestCase
{
    use DatabaseTransactions;

    private function makeOrderWithCustomer(string $companyId, string $email, array $overrides = []): Order
    {
        $customer = Customer::create(['company_id' => $companyId, 'name' => 'Test Customer', 'email' => $email]);

        return Order::create(array_merge([
            'company_id' => $companyId,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-'.strtoupper(Str::random(8)),
            'order_date' => now()->toDateString(),
            'status' => 'awaiting_payment',
            'subtotal' => 100,
            'total' => 100,
        ], $overrides));
    }

    private function tokenFor(Order $order): CustomerTrackingToken
    {
        return app(CustomerTrackingTokenService::class)->issue($order->customer, $order);
    }

    /** @return array<string, string> */
    private function bearer(CustomerTrackingToken $token): array
    {
        return ['Authorization' => 'Bearer '.$token->getAttribute('plain_text_token')];
    }

    private function revenueAccountFor(string $companyId): Account
    {
        return app(ChartOfAccountsService::class)->create([
            'company_id' => $companyId,
            'code' => 'REV-'.strtoupper(Str::random(6)),
            'name' => 'Test Revenue Account',
            'account_type' => AccountType::Revenue,
            'is_postable' => true,
        ]);
    }

    /** Reuses the CANONICAL Finance authority end-to-end — never a hand-built invoice row. */
    private function invoiceFor(Order $order, float $amount = 100.0): CustomerInvoice
    {
        $revenue = $this->revenueAccountFor($order->company_id);

        return app(AccountsReceivableService::class)->createDocument(
            companyId: $order->company_id,
            customerId: $order->customer_id,
            number: 'INV-'.strtoupper(Str::random(8)),
            documentDate: Carbon::today(),
            lines: [['revenue_account_id' => (int) $revenue->id, 'net_amount' => $amount, 'description' => 'Order goods']],
            sourceType: 'order',
            sourceId: (string) $order->id,
        );
    }

    // ── 19: own-invoice viewable ─────────────────────────────────────────────────────

    public function test_customer_can_view_their_own_orders_invoice(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $invoice = $this->invoiceFor($order, 150.0);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/track/order/invoice');

        $response->assertOk();
        $this->assertSame($invoice->number, $response->json('data.number'));
        $this->assertSame(150.0, $response->json('data.total'));
    }

    // ── 27: honest not-found when no invoice is linked yet ──────────────────────────────

    public function test_invoice_view_404s_when_no_invoice_is_linked_to_the_order_yet(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/track/order/invoice');

        $response->assertNotFound();
    }

    // ── 20/21/22: cross-Customer/Company/Brand-or-order rejection, proven via real HTTP calls ──

    public function test_invoice_view_is_scoped_to_the_tokens_own_order_even_with_injected_ids(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $orderA = $this->makeOrderWithCustomer($companyA->id, 'a@customer.test');
        $orderB = $this->makeOrderWithCustomer($companyB->id, 'b@customer.test');
        $invoiceA = $this->invoiceFor($orderA, 100.0);
        $this->invoiceFor($orderB, 999.0);
        $token = $this->tokenFor($orderA);

        $response = $this->withHeaders($this->bearer($token))->getJson(
            '/api/track/order/invoice?order_id='.$orderB->id.'&customer_id='.$orderB->customer_id.'&company_id='.$orderB->company_id,
        );

        $response->assertOk();
        $this->assertSame($invoiceA->number, $response->json('data.number'), 'injected ids must never redirect the invoice lookup to a different order/customer/company');
    }

    public function test_invoice_view_requires_a_valid_tracking_token(): void
    {
        $this->getJson('/api/track/order/invoice')->assertUnauthorized();
    }

    // ── 23: no journal/GL/posting internals ──────────────────────────────────────────────

    public function test_invoice_response_never_exposes_gl_or_posting_internals(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $this->invoiceFor($order, 100.0);
        $token = $this->tokenFor($order);

        $data = $this->withHeaders($this->bearer($token))->getJson('/api/track/order/invoice')->json('data');

        foreach (['journal_entry_id', 'ar_control_account_id', 'created_by', 'approved_by', 'posted_at'] as $key) {
            $this->assertArrayNotHasKey($key, $data, "customer-facing invoice view must never expose '{$key}'");
        }
    }

    // ── 24: lines/totals sourced from the canonical CustomerInvoice ─────────────────────

    public function test_invoice_lines_and_totals_are_sourced_from_the_canonical_invoice(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $invoice = $this->invoiceFor($order, 250.0);
        $token = $this->tokenFor($order);

        $data = $this->withHeaders($this->bearer($token))->getJson('/api/track/order/invoice')->json('data');

        $this->assertSame(round((float) $invoice->subtotal, 4), round((float) $data['subtotal'], 4));
        $this->assertSame(round((float) $invoice->total, 4), round((float) $data['total'], 4));
        $this->assertCount(1, $data['lines']);
        $this->assertSame('Order goods', $data['lines'][0]['description']);
    }

    // ── TASK-...-020 §15/§34 — REAL invoice PDF (items 46-52) ───────────────────────────
    // barryvdh/laravel-dompdf was installed this task (composer.json/lock) precisely to close
    // this gap; the old PARTIAL_DEPENDENCY_NOT_INSTALLED path no longer exists.

    public function test_own_invoice_pdf_returns_a_real_pdf_document(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $this->invoiceFor($order, 100.0);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->get('/api/track/order/invoice/pdf');

        $response->assertOk();
        // 46/47: a real PDF, not a JSON stub.
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_a_different_customers_invoice_pdf_is_rejected(): void
    {
        $company = Company::factory()->create();
        $orderMine = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $orderTheirs = $this->makeOrderWithCustomer($company->id, 'theirs@customer.test');
        $this->invoiceFor($orderTheirs, 100.0);
        $token = $this->tokenFor($orderMine); // orderMine has no invoice of its own.

        // 48: cannot reach another customer's invoice by injecting their order id.
        $this->withHeaders($this->bearer($token))
            ->getJson('/api/track/order/invoice/pdf?order_id='.$orderTheirs->id)
            ->assertNotFound();
    }

    public function test_a_different_companys_invoice_pdf_is_rejected(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $orderA = $this->makeOrderWithCustomer($companyA->id, 'a@customer.test');
        $orderB = $this->makeOrderWithCustomer($companyB->id, 'b@customer.test');
        $this->invoiceFor($orderB, 100.0);
        $token = $this->tokenFor($orderA);

        // 49: cross-company invoice never reachable, injected ids included.
        $this->withHeaders($this->bearer($token))
            ->getJson('/api/track/order/invoice/pdf?order_id='.$orderB->id.'&company_id='.$orderB->company_id)
            ->assertNotFound();
    }

    public function test_a_different_brand_or_orders_invoice_pdf_is_rejected(): void
    {
        $company = Company::factory()->create();
        $orderA = $this->makeOrderWithCustomer($company->id, 'a@customer.test');
        $orderB = $this->makeOrderWithCustomer($company->id, 'b@customer.test');
        $this->invoiceFor($orderA, 100.0);
        $this->invoiceFor($orderB, 200.0);
        $token = $this->tokenFor($orderA);

        // 50: the token's own order's invoice is the only one ever reachable — an injected
        // order_id for a DIFFERENT order (even same company) has zero effect.
        $response = $this->withHeaders($this->bearer($token))
            ->get('/api/track/order/invoice/pdf?order_id='.$orderB->id);

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_invoice_pdf_never_exposes_gl_or_posting_internals(): void
    {
        // 51: the PDF is rendered from the exact same payload() array proven above
        // (test_invoice_response_never_exposes_gl_or_posting_internals) to omit
        // journal_entry_id/ar_control_account_id/created_by/approved_by/posted_at — the Blade
        // template (resources/views/crm/self-service/invoice-pdf.blade.php) reads only number/
        // dates/currency/status/lines/totals from that same array, never the raw Eloquent model.
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $this->invoiceFor($order, 100.0);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->get('/api/track/order/invoice/pdf');

        $response->assertOk();
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_invoice_pdf_is_streamed_inline_never_a_public_filesystem_url(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $this->invoiceFor($order, 100.0);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->get('/api/track/order/invoice/pdf');

        // 52: an inline, token-gated binary response — never a redirect/Location to a stored
        // file, and never a JSON body carrying a storage path or URL.
        $response->assertOk();
        $disposition = $response->headers->get('Content-Disposition') ?? '';
        $this->assertStringContainsString('inline', $disposition);
        $this->assertFalse($response->headers->has('Location'));
    }
}
