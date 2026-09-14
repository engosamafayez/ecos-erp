<?php

declare(strict_types=1);

namespace Tests\Feature\Crm\SelfService;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\Customers\Domain\Models\Customer;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Crm\SelfService\Domain\Services\CustomerTrackingTokenService;
use Modules\Crm\Service\Domain\Enums\NoteVisibility;
use Modules\Crm\Service\Domain\Enums\TicketStatus;
use Modules\Crm\Service\Domain\Models\Ticket;
use Modules\Crm\Service\Domain\Services\TicketService;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripOrder;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-04-BACKEND-SOURCE-CLOSURE-REMEDIATION-019R1 §6 — items 28-48. Proves the
 * customer support surface (a) links every ticket via server-derived identity only, never a
 * client-supplied one (the RC-6 anti-pattern this controller is explicitly built not to repeat),
 * (b) is intake-only — it creates a Crm\Service Ticket and nothing else, never a refund,
 * inventory receipt, delivery-status or Order-status mutation, and (c) enforces the CORRECTED
 * §2 post-delivery window: General/payment/invoice support is never gated by delivery timing at
 * all, and the 4 post-delivery-specific categories fail CLOSED (not open) when the canonical
 * delivery timestamp cannot be established.
 */
final class CustomerSupportTest extends TestCase
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

    /** A Delivered order whose DeliveryStop completed $daysAgo days ago (or was never completed at all, when $daysAgo is null). */
    private function attachDeliveryStop(Order $order, ?int $daysAgo): void
    {
        $trip = Trip::create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $order->company_id,
            'trip_number' => 'TRIP-'.strtoupper(Str::random(6)),
            'name' => 'Test Trip',
            'status' => 'dispatched',
        ]);

        TripOrder::create(['trip_id' => $trip->id, 'order_id' => $order->id, 'assigned_at' => now()]);

        DeliveryStop::create([
            'trip_id' => $trip->id,
            'order_id' => $order->id,
            'status' => 'delivered',
            'completed_at' => $daysAgo !== null ? now()->subDays($daysAgo) : null,
        ]);
    }

    // ── 28-30: server-derived identity linkage ──────────────────────────────────────────

    public function test_ticket_is_linked_to_the_orders_server_derived_customer_and_brand(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::factory()->create(['company_id' => $company->id]);
        $channel = Channel::factory()->create(['brand_id' => $brand->id]);
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['channel_id' => $channel->id]);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'general_support',
            'subject' => 'Where is my order',
        ]);

        $response->assertCreated();
        $ticket = Ticket::query()->latest('created_at')->first();
        $this->assertSame($order->customer_id, $ticket->customer_id);
        $this->assertSame($company->id, $ticket->company_id);
        $this->assertSame($brand->id, $ticket->source_reference['brand_id'] ?? null);
    }

    public function test_client_supplied_customer_id_is_ignored_never_the_ticket_owner(): void
    {
        $company = Company::factory()->create();
        $orderMine = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $orderTheirs = $this->makeOrderWithCustomer($company->id, 'theirs@customer.test');
        $token = $this->tokenFor($orderMine);

        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'general_support',
            'subject' => 'Question',
            'customer_id' => $orderTheirs->customer_id,
            'company_id' => $orderTheirs->company_id,
        ])->assertCreated();

        $ticket = Ticket::query()->latest('created_at')->first();
        $this->assertSame($orderMine->customer_id, $ticket->customer_id, 'the exact RC-6 anti-pattern this controller must never repeat');
    }

    // ── 31: source_reference carries canonical order linkage ────────────────────────────

    public function test_source_reference_carries_the_canonical_order_linkage(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $token = $this->tokenFor($order);

        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'general_support',
            'subject' => 'Question',
        ])->assertCreated();

        $ticket = Ticket::query()->latest('created_at')->first();
        $this->assertSame($order->id, $ticket->source_reference['order_id'] ?? null);
        $this->assertSame($order->order_number, $ticket->source_reference['order_number'] ?? null);
        $this->assertSame('customer_portal', $ticket->source_reference['channel'] ?? null);
    }

    // ── public/internal note visibility ──────────────────────────────────────────────────

    public function test_index_exposes_only_public_notes_never_internal_ones(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test');
        $token = $this->tokenFor($order);

        $ticket = app(TicketService::class)->create($company->id, $order->customer_id, \Modules\Crm\Service\Domain\Enums\TicketType::Ticket, [
            'subject' => 'A case', 'channel' => 'customer_portal',
        ]);
        app(TicketService::class)->addNote($ticket, NoteVisibility::Public, 'We are looking into it.', 1);
        app(TicketService::class)->addNote($ticket, NoteVisibility::Internal, 'Staff-only escalation detail.', 1);

        $response = $this->withHeaders($this->bearer($token))->getJson('/api/track/order/support');

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringContainsString('We are looking into it.', $body);
        $this->assertStringNotContainsString('Staff-only escalation detail.', $body, 'an internal note must never reach the customer-facing endpoint');
    }

    // ── intake-only: no refund/inventory/delivery/Order-status mutation ────────────────

    public function test_wrong_item_creates_an_intake_ticket_without_mutating_the_order(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'delivered']);
        $this->attachDeliveryStop($order, 5);
        $token = $this->tokenFor($order);
        $statusBefore = $order->status;
        $depositBefore = $order->deposit_amount;

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'wrong_item',
            'subject' => 'Wrong item received',
        ]);

        $response->assertCreated();
        $ticket = Ticket::query()->latest('created_at')->first();
        $this->assertSame(TicketStatus::New, $ticket->status, 'a submitted case is intake-only — never auto-resolved or auto-refunded');
        $fresh = $order->fresh();
        $this->assertSame($statusBefore, $fresh->status, 'submitting an issue must never change Order.status');
        $this->assertEquals($depositBefore, $fresh->deposit_amount, 'submitting an issue must never touch payment/refund amounts');
    }

    public function test_damaged_item_and_missing_item_and_return_request_map_to_intake_categories(): void
    {
        $company = Company::factory()->create();

        foreach (['damaged_item' => 'return_rma', 'missing_item' => 'complaint', 'return_request' => 'return_rma'] as $category => $expectedType) {
            $order = $this->makeOrderWithCustomer($company->id, $category.'@customer.test', ['status' => 'delivered']);
            $this->attachDeliveryStop($order, 3);
            $token = $this->tokenFor($order);

            $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
                'category' => $category,
                'subject' => 'Issue: '.$category,
            ])->assertCreated();

            $ticket = Ticket::query()->where('customer_id', $order->customer_id)->latest('created_at')->first();
            $this->assertSame($expectedType, $ticket->type->value, "category {$category} must map to the correct intake type");
        }
    }

    // ── corrected §2 post-delivery window enforcement ───────────────────────────────────

    public function test_post_delivery_category_is_allowed_within_the_thirty_day_window(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'delivered']);
        $this->attachDeliveryStop($order, 10);
        $token = $this->tokenFor($order);

        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'wrong_item',
            'subject' => 'Wrong item',
        ])->assertCreated();
    }

    public function test_post_delivery_category_is_blocked_after_the_thirty_day_window(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'delivered']);
        $this->attachDeliveryStop($order, 40);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'wrong_item',
            'subject' => 'Wrong item',
        ]);

        $response->assertStatus(422);
        $this->assertSame('post_delivery_window_expired', $response->json('reason'));
    }

    public function test_post_delivery_category_fails_closed_when_the_delivery_timestamp_cannot_be_established(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'delivered']);
        $this->attachDeliveryStop($order, null); // Delivered status, but the stop never actually completed.
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'return_request',
            'subject' => 'Return',
        ]);

        // TASK-...-019R1 §2 — the corrected behaviour: an unprovable delivery timestamp must
        // fail CLOSED for this timing-dependent category, never silently permit it.
        $response->assertStatus(422);
        $this->assertSame('delivery_timestamp_unavailable', $response->json('reason'));
    }

    public function test_post_delivery_category_is_blocked_when_the_order_has_not_been_delivered_at_all(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'out_for_delivery']);
        $token = $this->tokenFor($order);

        $response = $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'missing_item',
            'subject' => 'Missing item',
        ]);

        $response->assertStatus(422);
        $this->assertSame('not_yet_delivered', $response->json('reason'));
    }

    // ── general/payment/invoice support is NEVER gated by delivery timing ───────────────

    public function test_general_support_is_always_available_even_when_the_delivery_timestamp_is_unprovable(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'delivered']);
        $this->attachDeliveryStop($order, null);
        $token = $this->tokenFor($order);

        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'general_support',
            'subject' => 'General question',
        ])->assertCreated();
    }

    public function test_payment_issue_is_never_gated_by_delivery_timing(): void
    {
        $company = Company::factory()->create();
        // Not delivered at all — a post-delivery category would be blocked here, but
        // payment_issue must never even consult that rule.
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'awaiting_payment']);
        $token = $this->tokenFor($order);

        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'payment_issue',
            'subject' => 'Payment question',
        ])->assertCreated();
    }

    public function test_invoice_issue_is_never_gated_by_delivery_timing(): void
    {
        $company = Company::factory()->create();
        $order = $this->makeOrderWithCustomer($company->id, 'mine@customer.test', ['status' => 'in_progress']);
        $token = $this->tokenFor($order);

        $this->withHeaders($this->bearer($token))->postJson('/api/track/order/support', [
            'category' => 'invoice_issue',
            'subject' => 'Invoice question',
        ])->assertCreated();
    }
}
