<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ECOS-MOBILE-REMAINING-PAGES-DATA-COMPLETENESS-SOURCE-CLOSURE-005 §9 —
 * the Mobile Order card's "has note" indicator only ever read the legacy
 * notes/customer_note columns, never the orderNotes relation most notes are
 * actually added to today (the drawer's Notes tab). Eager-loading the full
 * thread on the LIST endpoint (as the single-order detail fetch already does)
 * would mean loading every note row for every order on the page — a real
 * N+1-shaped cost, not a bounded one. `withCount('orderNotes')` is the
 * one-aggregate-query fix: a single extra count subquery for the whole page.
 *
 * These tests exercise the real HTTP list/detail/note-create routes — no
 * mocked repository, no mocked resource — proving the exact contract the
 * Mobile card now consumes.
 */
class OrderNotesCountReadModelTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    private Product $product;

    private Channel $channel;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create(['company_id' => $this->company->id]);
        $brand = Brand::factory()->create(['company_id' => $this->company->id]);
        // Not Channel::factory(): its definition() unconditionally sets a
        // `company_id` attribute (with its own comment explaining the column
        // used to be NOT NULL there) that no longer exists on the real `channels`
        // table (confirmed via DESCRIBE against canonical DEV) — a pre-existing,
        // unrelated ChannelFactory/schema drift, out of this task's scope
        // (Commerce\Channels test infrastructure, not Mobile data completeness).
        // Reported as a non-blocking quality note; worked around here by
        // constructing the row directly with only the columns that are real.
        $this->channel = Channel::create([
            'brand_id' => $brand->id,
            'name' => 'Test Channel',
            'platform' => 'woocommerce',
            'store_url' => 'https://example.test',
            'is_active' => true,
            'sync_products' => true,
            'sync_prices' => true,
            'sync_stock' => true,
        ]);
        $this->user = User::factory()->create(['company_id' => $this->company->id]);

        $role = Role::create(['name' => 'System Admin', 'slug' => 'sysadmin', 'is_system' => true]);
        $this->user->roles()->attach($role->id);
    }

    private function createOrder(): Order
    {
        $response = $this->actingAs($this->user)->postJson('/api/orders/manual', [
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'channel_id' => $this->channel->id,
            'order_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ]);

        self::assertContains($response->status(), [200, 201], 'Order creation failed: '.$response->getContent());

        return Order::query()->findOrFail($response->json('data.id') ?? $response->json('id'));
    }

    public function test_list_endpoint_reports_zero_notes_count_for_an_order_with_no_notes(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->user)->getJson('/api/orders')->assertOk();

        $row = collect($response->json('data.items'))->firstWhere('id', $order->id);
        $this->assertNotNull($row, 'Created order not found in list response.');
        $this->assertSame(0, $row['notes_count']);
    }

    public function test_list_endpoint_reports_the_correct_notes_count_after_notes_are_added(): void
    {
        $order = $this->createOrder();

        $this->actingAs($this->user)
            ->postJson("/api/orders/{$order->id}/notes", ['content' => 'First note', 'type' => 'internal'])
            ->assertOk();
        $this->actingAs($this->user)
            ->postJson("/api/orders/{$order->id}/notes", ['content' => 'Second note', 'type' => 'customer'])
            ->assertOk();

        $response = $this->actingAs($this->user)->getJson('/api/orders')->assertOk();

        $row = collect($response->json('data.items'))->firstWhere('id', $order->id);
        $this->assertNotNull($row);
        $this->assertSame(2, $row['notes_count']);
    }

    public function test_notes_count_never_inflates_across_unrelated_orders_no_n_plus_one_leakage(): void
    {
        $noted = $this->createOrder();
        $unnoted = $this->createOrder();

        $this->actingAs($this->user)
            ->postJson("/api/orders/{$noted->id}/notes", ['content' => 'Only on the first order'])
            ->assertOk();

        $response = $this->actingAs($this->user)->getJson('/api/orders')->assertOk();
        $items = collect($response->json('data.items'));

        $this->assertSame(1, $items->firstWhere('id', $noted->id)['notes_count']);
        $this->assertSame(0, $items->firstWhere('id', $unnoted->id)['notes_count']);
    }

    public function test_detail_endpoint_is_unaffected_and_still_carries_the_full_note_thread(): void
    {
        $order = $this->createOrder();
        $this->actingAs($this->user)
            ->postJson("/api/orders/{$order->id}/notes", ['content' => 'A detail-visible note'])
            ->assertOk();

        $response = $this->actingAs($this->user)->getJson("/api/orders/{$order->id}")->assertOk();

        // The detail read model (WITH_DETAIL) never calls withCount — it loads the
        // full thread instead, so notes_count is correctly absent here, not zero.
        $this->assertArrayNotHasKey('notes_count', $response->json('data'));
        $this->assertCount(1, $response->json('data.order_notes_list'));
        $this->assertSame('A detail-visible note', $response->json('data.order_notes_list.0.content'));
    }
}
