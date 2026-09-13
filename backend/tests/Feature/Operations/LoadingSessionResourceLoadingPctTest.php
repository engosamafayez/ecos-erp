<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Domain\Enums\LoadingSessionStatus;
use Modules\Operations\Loading\Domain\Enums\SessionType;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Presentation\Http\Resources\LoadingSessionResource;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-OPS-01-LOADING-CUSTODY-CLOSURE-044A — `loading_pct` must report
 * NULL (not yet determinable) rather than a counted 0% when nothing has been
 * planned/allocated to load yet. A counted 0% would misreport "not started" the
 * same way a fake zero would for any other not-yet-determinable quantity.
 *
 * NOT YET EXECUTED per the FINAL HOLD / consolidated-testing policy in effect
 * for this task — written and reviewed statically only.
 */
final class LoadingSessionResourceLoadingPctTest extends TestCase
{
    use RefreshDatabase;

    public function test_loading_pct_is_null_when_nothing_is_planned_to_load_yet(): void
    {
        $session = $this->loadingSession(totalUnitsToLoad: 0.0, totalUnitsLoaded: 0.0);

        $data = (new LoadingSessionResource($session))->toArray(new Request());

        self::assertNull($data['loading_pct']);
    }

    public function test_loading_pct_still_computes_normally_once_something_is_planned(): void
    {
        $session = $this->loadingSession(totalUnitsToLoad: 40.0, totalUnitsLoaded: 10.0);

        $data = (new LoadingSessionResource($session))->toArray(new Request());

        self::assertSame(25.0, $data['loading_pct']);
    }

    private function loadingSession(float $totalUnitsToLoad, float $totalUnitsLoaded): LoadingSession
    {
        $company = Company::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id]);
        $suffix = substr(md5(uniqid('', true)), 0, 8);
        $actorId = (string) Str::uuid();

        $session = LoadingSession::create([
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'session_number' => 'LS-'.$suffix,
            'operational_date' => now()->toDateString(),
            'status' => LoadingSessionStatus::Loading->value,
            'session_type' => SessionType::Standard->value,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]);

        $session->update([
            'total_units_to_load' => $totalUnitsToLoad,
            'total_units_loaded' => $totalUnitsLoaded,
        ]);

        return $session->fresh();
    }
}
