<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountLine;
use Modules\Inventory\CountSessions\Domain\Models\InventoryCountSession;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Inventory\WasteInvestigations\Domain\Models\WasteInvestigation;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * 035B-R1 — WasteInvestigationController::report() used PostgreSQL-only
 * EXTRACT(EPOCH FROM ...) and DATE_TRUNC('week', ...) on a MySQL-only
 * platform; every request to this endpoint threw SQLSTATE 42000 / 1064.
 * Proves the MySQL-native replacements produce the exact same numbers a
 * correct implementation should, not just that the query no longer errors.
 */
final class WasteInvestigationReportMySqlTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private function lineFor(Company $company, Warehouse $warehouse, Product $product): InventoryCountLine
    {
        $session = InventoryCountSession::query()->create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'count_number' => 'CNT-'.uniqid(),
        ]);

        return InventoryCountLine::query()->create([
            'session_id' => $session->id,
            'product_id' => $product->id,
        ]);
    }

    private function investigation(
        Company $company,
        Warehouse $warehouse,
        Product $product,
        InventoryCountLine $line,
        string $createdAt,
        ?string $resolvedAt,
        float $value,
    ): WasteInvestigation {
        $investigation = WasteInvestigation::query()->create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'count_session_id' => $line->session_id,
            'count_line_id' => $line->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'damage_reason' => 'damaged_in_transit',
            'status' => $resolvedAt !== null ? 'resolved' : 'pending_investigation',
            'outcome' => $resolvedAt !== null ? 'warehouse_responsibility' : null,
            'month' => '2026-06',
            'cost_snapshot_total_value' => $value,
            'resolved_at' => $resolvedAt,
        ]);

        // Overwrite the auto-set created_at with an exact, known value so the
        // average-hours and weekly-grouping math is independently verifiable,
        // without re-touching updated_at or re-firing the 'created' listener.
        $investigation->forceFill(['created_at' => $createdAt])->saveQuietly();

        return $investigation;
    }

    public function test_report_executes_on_mysql_and_aggregates_correctly(): void
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);
        $product = Product::factory()->create(['company_id' => $company->id]);
        $line = $this->lineFor($company, $warehouse, $product);

        // Monday 2026-06-01 00:00:00 → resolved 2 hours later.
        $this->investigation($company, $warehouse, $product, $line, '2026-06-01 08:00:00', '2026-06-01 10:00:00', 100.0);
        // Same ISO week (Wed 2026-06-03) → resolved 6 hours later. Average: (2+6)/2 = 4h.
        $this->investigation($company, $warehouse, $product, $line, '2026-06-03 08:00:00', '2026-06-03 14:00:00', 300.0);
        // Next ISO week (Mon 2026-06-08), still pending.
        $this->investigation($company, $warehouse, $product, $line, '2026-06-08 09:00:00', null, 50.0);

        $user = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAsUnprivileged($user)
            ->getJson('/api/inventory/waste-investigations/report?month=2026-06')
            ->assertOk()
            ->json();

        self::assertSame(3, $response['total_items']);
        self::assertSame(2, $response['resolved']);
        self::assertSame(1, $response['pending']);
        // (2h + 6h) / 2 = 4.0 — proves TIMESTAMPDIFF(SECOND,...)/3600 matches
        // PostgreSQL's EXTRACT(EPOCH FROM (resolved_at - created_at))/3600 exactly.
        self::assertSame(4.0, $response['avg_resolution_hours']);

        // Two distinct week buckets, each anchored on its ISO week's Monday —
        // proves the WEEKDAY()-based expression matches DATE_TRUNC('week', ...).
        $weeks = collect($response['trend'])->pluck('week')->map(fn ($w) => substr((string) $w, 0, 10))->sort()->values();
        self::assertSame(['2026-06-01', '2026-06-08'], $weeks->all());

        $firstWeek = collect($response['trend'])->firstWhere(fn ($row) => str_starts_with((string) $row['week'], '2026-06-01'));
        self::assertSame(2, (int) $firstWeek['count']);
        self::assertEqualsWithDelta(400.0, (float) $firstWeek['total_value'], 0.001);
    }
}
