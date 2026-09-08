<?php

declare(strict_types=1);

namespace Modules\Operations\ShippingOrders\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Logistics\Drivers\Domain\Models\DriverVehicleAssignment;
use Modules\Logistics\ShippingCompanies\Domain\Models\ShippingCompany;
use Modules\Operations\ShippingOrders\Domain\Enums\ShippingOrderClassification;
use Modules\Operations\ShippingOrders\Domain\Services\ShippingOrderReadModel;
use Modules\Operations\ShippingOrders\Presentation\Http\Resources\ShippingOrderResource;

/**
 * TASK-ECOS-OPERATIONS-SHIPPING-ORDERS-IMPLEMENTATION-002. Read-only monitoring
 * surface (§32 of the task) — no mutation action lives here; Driver operational
 * actions remain in the existing canonical Driver flow.
 */
final class ShippingOrderController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly CurrentCompanyService $currentCompany,
        private readonly ShippingOrderReadModel $readModel,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->currentCompany->id();

        $query = $this->readModel->baseQuery($companyId);
        $this->readModel->applyEligibility($query);
        $this->applyFilters($query, $request, includeClassification: true);
        $this->applySearch($query, $request);

        $perPage = max(1, (int) $request->query('per_page', 20));
        $page = max(1, (int) $request->query('page', 1));
        $paginator = $this->readModel->paginate($query, $perPage, $page);

        $this->attachDriverAndShippingCompanyNames($paginator->items());

        return $this->success([
            'items' => collect($paginator->items())
                ->map(fn ($row) => (new ShippingOrderResource($row, $this->readModel))->toArray($request))
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'counts' => $this->tabCounts($companyId, $request),
            ],
        ]);
    }

    /**
     * TASK-...-IMPLEMENTATION-002 §25 ("PAGE TABS"), Architecture-001 §24 ("Tab
     * Counts / Filter Architecture") — tab counts computed from the FULL
     * filtered population (every filter except the classification tab itself, so
     * every tab's count is visible at once), never from only the current
     * pagination page. One bounded, grouped query — not one count-query per tab.
     *
     * @return array<string, int>
     */
    private function tabCounts(string $companyId, Request $request): array
    {
        $query = $this->readModel->joinedQuery($companyId);
        $this->readModel->applyEligibility($query);
        $this->applyFilters($query, $request, includeClassification: false);
        $this->applySearch($query, $request);

        $rows = $query
            ->selectRaw('('.ShippingOrderReadModel::classificationSql().') as classification, COUNT(*) as cnt')
            ->groupBy('classification')
            ->pluck('cnt', 'classification');

        $counts = ['all' => 0];
        foreach (ShippingOrderClassification::values() as $value) {
            $counts[$value] = (int) ($rows[$value] ?? 0);
            $counts['all'] += $counts[$value];
        }

        return $counts;
    }

    private function applyFilters(Builder $query, Request $request, bool $includeClassification): void
    {
        if ($includeClassification) {
            $classification = $request->query('classification');
            if (is_string($classification) && $classification !== '' && $classification !== 'all') {
                $query->whereRaw(
                    '('.ShippingOrderReadModel::classificationSql().') = ?',
                    [$classification],
                );
            }
        }

        // §27 — the operational day is the Trip's own execution milestone, never
        // Order.created_at. Falls back progressively through earlier lifecycle
        // timestamps so an "Assigned Driver" order (custody confirmed, trip not yet
        // started) still has a known operational day instead of disappearing from
        // every date filter until the trip literally starts moving. Reuses the SAME
        // shared expression the read model's own default ORDER BY uses (Task 003
        // §20/§21) — never duplicated as a second, driftable copy.
        $dateFrom = $request->query('date_from');
        if (is_string($dateFrom) && $dateFrom !== '') {
            $query->whereRaw(
                ShippingOrderReadModel::OPERATIONAL_DATE_SQL.' >= ?',
                [$dateFrom.' 00:00:00'],
            );
        }
        $dateTo = $request->query('date_to');
        if (is_string($dateTo) && $dateTo !== '') {
            $query->whereRaw(
                ShippingOrderReadModel::OPERATIONAL_DATE_SQL.' <= ?',
                [$dateTo.' 23:59:59'],
            );
        }

        $brandId = $request->query('brand_id');
        if (is_string($brandId) && $brandId !== '') {
            $query->whereHas('channel', fn (Builder $q) => $q->where('brand_id', $brandId));
        }

        $shippingCompanyId = $request->query('shipping_company_id');
        if (is_string($shippingCompanyId) && $shippingCompanyId !== '') {
            $query->where('trip.shipping_company_id', $shippingCompanyId);
        }

        $paymentStatus = $request->query('payment_status');
        if (is_string($paymentStatus) && $paymentStatus !== '') {
            $query->where('orders.payment_state', $paymentStatus);
        }

        $driverId = $request->query('driver_id');
        if (is_string($driverId) && $driverId !== '') {
            $query->whereIn('trip.driver_vehicle_assignment_id', function ($sub) use ($driverId) {
                $sub->select('id')
                    ->from('logistics_driver_vehicle_assignments')
                    ->where('driver_id', $driverId);
            });
        }

        // TASK-ECOS-SHIPPING-OS-REDESIGN-003 §15 — precise cross-surface deep links
        // (Dispatch & Execution's Active Trips -> "this trip's shipping orders")
        // need an exact-Trip filter, not the closest existing proxy (driver_id, which
        // would show that driver's OTHER trips' orders too). `trip.id` is already the
        // joined alias every other trip-derived filter above uses.
        $tripId = $request->query('trip_id');
        if (is_string($tripId) && $tripId !== '') {
            $query->where('trip.id', $tripId);
        }
    }

    /** §26 — Order Number, Customer Name/Number, Driver Name/Number. */
    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->query('search', ''));
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $q) use ($search) {
            $like = '%'.$search.'%';
            $q->where('orders.order_number', 'like', $like)
                ->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', $like)->orWhere('code', 'like', $like))
                ->orWhereIn('trip.driver_vehicle_assignment_id', function ($sub) use ($like) {
                    $sub->select('logistics_driver_vehicle_assignments.id')
                        ->from('logistics_driver_vehicle_assignments')
                        ->join('logistics_drivers', 'logistics_drivers.id', '=', 'logistics_driver_vehicle_assignments.driver_id')
                        ->where('logistics_drivers.full_name', 'like', $like)
                        ->orWhere('logistics_drivers.driver_code', 'like', $like);
                });
        });
    }

    /**
     * Task §14/§22, Architecture-001 §23 ("Query / N+1 Architecture") — batch driver name/code and shipping-company name for the CURRENT
     * PAGE ONLY, grouped by the page's own distinct IDs, mirroring the exact
     * established N+1-avoidance pattern (batch per page, never per row) already used
     * elsewhere in Commerce Orders (e.g. CustomerOrderMetricsService::forCustomers()).
     *
     * @param  array<int, \Modules\Commerce\Orders\Domain\Models\Order>  $rows
     */
    private function attachDriverAndShippingCompanyNames(array $rows): void
    {
        $assignmentIds = collect($rows)
            ->map(fn ($row) => $row->getAttribute('trip_driver_vehicle_assignment_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $driverByAssignment = [];
        if ($assignmentIds !== []) {
            $driverByAssignment = DriverVehicleAssignment::query()
                ->whereIn('id', $assignmentIds)
                ->with('driver:id,full_name,driver_code')
                ->get()
                ->mapWithKeys(fn (DriverVehicleAssignment $a) => [
                    $a->id => $a->driver !== null ? [
                        'full_name' => $a->driver->full_name,
                        'driver_code' => $a->driver->driver_code,
                    ] : null,
                ])
                ->all();
        }

        $shippingCompanyIds = collect($rows)
            ->map(fn ($row) => $row->getAttribute('trip_shipping_company_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $shippingCompanyNames = $shippingCompanyIds !== []
            ? ShippingCompany::query()->whereIn('id', $shippingCompanyIds)->pluck('name', 'id')
            : collect();

        foreach ($rows as $row) {
            $assignmentId = $row->getAttribute('trip_driver_vehicle_assignment_id');
            $driver = $assignmentId !== null ? ($driverByAssignment[$assignmentId] ?? null) : null;
            $row->setAttribute('driver_full_name', $driver['full_name'] ?? null);
            $row->setAttribute('driver_code', $driver['driver_code'] ?? null);

            $shippingCompanyId = $row->getAttribute('trip_shipping_company_id');
            $row->setAttribute(
                'shipping_company_name_resolved',
                $shippingCompanyId !== null ? ($shippingCompanyNames[$shippingCompanyId] ?? null) : null,
            );
        }
    }
}
