<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\Customers\Domain\Enums\CustomerType;
use Modules\Crm\Customers\Domain\Services\CustomerService;
use Modules\Crm\Service\Domain\Models\Ticket;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * CRM-01 TASK 1 — canonical-surface parity closure.
 *
 * Covers the capabilities added to the canonical `/crm/customers` workspace so
 * the legacy `/customers` (Sales) workspace can be retired from navigation
 * without losing anything a user could previously do there: sales-owner
 * assignment, block/unblock, and the new Customer 360 Orders/Tickets
 * composition. See CRM-01 Task 1 report, "Customer UI" / "Customer 360".
 */
final class CustomerCanonicalSurfaceTest extends TestCase
{
    use DatabaseTransactions;

    private function customerService(): CustomerService
    {
        return app(CustomerService::class);
    }

    // ═══ SALES-OWNER ASSIGNMENT ══════════════════════════════════════════════

    public function test_assign_owner_sets_sales_owner_via_canonical_endpoint(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $owner = User::factory()->create(['company_id' => $company->id, 'name' => 'Nour Sales']);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Ali', 'phone' => '01000000001']);

        $response = $this->actingAs($user)->patchJson("/api/crm/customers/{$customer->id}/sales-owner", [
            'sales_owner_id' => $owner->id,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'sales_owner_id' => $owner->id,
        ]);
    }

    public function test_assign_owner_rejects_a_user_from_another_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $foreignOwner = User::factory()->create(['company_id' => $otherCompany->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Sara', 'phone' => '01000000002']);

        $response = $this->actingAs($user)->patchJson("/api/crm/customers/{$customer->id}/sales-owner", [
            'sales_owner_id' => $foreignOwner->id,
        ]);

        $response->assertStatus(422);
    }

    // ═══ BLOCK / UNBLOCK ══════════════════════════════════════════════════════

    public function test_block_and_unblock_round_trip_via_canonical_endpoint(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Mona', 'phone' => '01000000003']);

        $block = $this->actingAs($user)->postJson("/api/crm/customers/{$customer->id}/block", [
            'reason' => 'Repeated unpaid COD returns',
        ]);
        $block->assertOk();

        $profile = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/profile");
        $profile->assertOk();
        $this->assertTrue($profile->json('data.blocked.is_blocked'));
        $blockId = $profile->json('data.blocked.id');
        $this->assertNotNull($blockId);

        $unblock = $this->actingAs($user)->postJson("/api/crm/customers/{$customer->id}/unblock", [
            'block_id' => $blockId,
            'reason' => 'Confirmed with the customer',
        ]);
        $unblock->assertOk();

        $profileAfter = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/profile");
        $this->assertFalse($profileAfter->json('data.blocked.is_blocked'));
    }

    public function test_block_history_is_scoped_to_the_acting_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $otherUser = User::factory()->create(['company_id' => $otherCompany->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Hany', 'phone' => '01000000004']);

        $this->actingAs($user)->postJson("/api/crm/customers/{$customer->id}/block", ['reason' => 'Test block']);

        // A user from another company cannot even resolve this customer id.
        $foreign = $this->actingAs($otherUser)->getJson("/api/crm/customers/{$customer->id}/block-history");
        $foreign->assertStatus(404);

        $own = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/block-history");
        $own->assertOk();
        $this->assertCount(1, $own->json('data'));
    }

    // ═══ CUSTOMER 360 — ORDERS / TICKETS COMPOSITION ═════════════════════════

    public function test_orders_tab_reads_canonical_commerce_orders_scoped_to_company(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Youssef', 'phone' => '01000000005']);

        Order::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-CANON-001',
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 250.5,
            'total' => 250.5,
        ]);
        // A same-customer order booked under another company must never leak in.
        Order::create([
            'company_id' => $otherCompany->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-OTHER-001',
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 10,
            'total' => 10,
        ]);

        $response = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/orders");

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('ORD-CANON-001', $rows[0]['order_number']);
    }

    public function test_tickets_tab_reads_canonical_service_tickets_scoped_to_customer(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $customer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Laila', 'phone' => '01000000006']);
        $otherCustomer = $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'Other', 'phone' => '01000000007']);

        Ticket::create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'ticket_number' => 'CASE-0001',
            'type' => 'complaint',
            'subject' => 'Late delivery',
            'status' => 'open',
            'priority' => 'normal',
        ]);
        Ticket::create([
            'company_id' => $company->id,
            'customer_id' => $otherCustomer->id,
            'ticket_number' => 'CASE-0002',
            'type' => 'complaint',
            'subject' => 'Should not appear',
            'status' => 'open',
            'priority' => 'normal',
        ]);

        $response = $this->actingAs($user)->getJson("/api/crm/customers/{$customer->id}/tickets");

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('CASE-0001', $rows[0]['ticket_number']);
    }

    public function test_export_returns_a_csv_stream(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $this->customerService()->create((string) $company->id, CustomerType::Individual, ['first_name' => 'ExportMe', 'phone' => '01000000008']);

        $response = $this->actingAs($user)->get('/api/crm/customers/export');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('customers.csv', (string) $response->headers->get('content-disposition'));
    }
}
