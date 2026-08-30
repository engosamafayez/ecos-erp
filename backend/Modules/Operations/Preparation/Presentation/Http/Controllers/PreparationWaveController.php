<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Presentation\Http\Controllers;

use App\Core\Documents\DocumentService;
use App\Core\FeatureFlags\FeatureFlagService;
use App\Core\Timeline\TimelineService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\Preparation\Application\Actions\AnalyzeMaterialsAction;
use Modules\Operations\Preparation\Application\Actions\ApproveWaveAction;
use Modules\Operations\Preparation\Application\Actions\AssignWorkerAction;
use Modules\Operations\Preparation\Application\Actions\CancelWaveAction;
use Modules\Operations\Preparation\Application\Actions\CompleteProductAction;
use Modules\Operations\Preparation\Application\Actions\CompleteWaveAction;
use Modules\Operations\Preparation\Application\Actions\CreateWaveAction;
use Modules\Operations\Preparation\Application\Actions\GenerateDemandAction;
use Modules\Operations\Preparation\Application\Actions\RecalculateWaveAction;
use Modules\Operations\Preparation\Application\Actions\ReleaseWorkerAction;
use Modules\Operations\Preparation\Application\Actions\ReportIssueAction;
use Modules\Operations\Preparation\Application\Actions\ResolveShortageAction;
use Modules\Operations\Preparation\Application\Actions\StartPreparationAction;
use Modules\Operations\Preparation\Application\DTOs\CreateWaveDTO;
use Modules\Operations\Preparation\Application\DTOs\RecalculateWaveDTO;
use Modules\Operations\Preparation\Application\DTOs\ReportIssueDTO;
use Modules\Operations\Preparation\Application\DTOs\StartPreparationDTO;
use Modules\Operations\Preparation\Application\Services\PreparationReleaseEngine;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveMembershipService;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WavePreparationService;
use Modules\Operations\Preparation\Domain\Enums\PreparationIssueType;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Exceptions\WaveItemNotFoundException;
use Modules\Operations\Preparation\Domain\Exceptions\WaveNotFoundException;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\PreparationWaveItem;
use Modules\Operations\Preparation\Presentation\Http\Requests\ApproveWaveRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\AssignWorkerRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\CancelWaveRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\CompleteProductRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\CreateWaveRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\RecalculateWaveRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\ReleaseWorkerRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\ReportIssueRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\ResolveShortageRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\StartPreparationRequest;
use Modules\Operations\Preparation\Presentation\Http\Resources\PreparationWaveResource;

final class PreparationWaveController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly FeatureFlagService $flags,
        private readonly PreparationReleaseEngine $releaseEngine,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $this->authorize('viewAny', PreparationWave::class);

        $request->validate([
            'status' => ['nullable', 'string'],
            'warehouse_id' => ['nullable', 'uuid'],
            'planning_date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'string'],
            'lifecycle' => ['nullable', 'in:active,archived,all'],
        ]);

        $companyId = $request->user()->company_id;
        $perPage = (int) ($request->query('per_page', 25));

        $sortable = ['wave_number', 'planning_date', 'status', 'orders_count', 'created_at'];
        // planning_date is the OPERATIONAL ordering field. Sorting the workspace by
        // created_at surfaced stale waves as current, because a wave created earlier
        // can be planned for a later day.
        $sortRaw = $request->query('sort', '-planning_date');
        $desc = str_starts_with((string) $sortRaw, '-');
        $sortCol = ltrim((string) $sortRaw, '-');
        $sortCol = in_array($sortCol, $sortable, true) ? $sortCol : 'planning_date';

        $query = PreparationWave::with(['workers' => fn ($q) => $q->whereNull('released_at')])
            ->where('company_id', $companyId)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->query('planning_date'), fn ($q, $v) => $q->whereDate('planning_date', $v))
            ->when($request->query('search'), fn ($q, $v) => $q->where('wave_number', 'like', "%{$v}%"))
            // Active vs archived is a READ/FILTER concern only. No wave record is
            // modified and nothing is deleted; the split reuses the existing
            // WaveStatus lifecycle contract rather than introducing a new flag.
            ->when(
                $request->query('lifecycle', 'all') !== 'all',
                fn ($q) => $request->query('lifecycle') === 'active'
                    ? $q->whereIn('status', WaveStatus::activeValues())
                    : $q->whereIn('status', WaveStatus::terminalValues()),
            )
            ->orderBy($sortCol, $desc ? 'desc' : 'asc');

        $paginator = $query->paginate($perPage);

        return $this->success([
            'data' => PreparationWaveResource::collection($paginator->items()),
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * The canonical CURRENT active preparation wave for the operator's company
     * (TASK-PREPARATION-WORKSPACE-MOBILE-UX-ACTIVE-WAVE-001 §3-§6).
     *
     * "Active" reuses the existing WaveStatus lifecycle (non-terminal) exactly as the list's
     * `lifecycle=active` filter does — this is a READ over the same contract, it opens/closes
     * no wave and introduces no new status. The three operational cases are returned
     * explicitly so the client never has to guess and never silently picks one of several:
     *
     *   active_count = 0  → no current wave  (client shows "No Active Preparation Wave")
     *   active_count = 1  → wave populated    (client auto-opens it)
     *   active_count > 1  → wave = null       (client shows a safe multiple-active state and
     *                                           lists the conflicting waves — §6, no silent pick)
     *
     * Read-only; tenant-scoped to the acting company like every other wave read.
     */
    public function current(Request $request): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $this->authorize('viewAny', PreparationWave::class);

        $active = PreparationWave::query()
            ->where('company_id', $request->user()->company_id)
            ->whereIn('status', WaveStatus::activeValues())
            // Same operational ordering the workspace list uses: a wave created earlier can
            // be planned for a later day, so planning_date decides "most current".
            ->orderByDesc('planning_date')
            ->orderByDesc('created_at')
            ->get();

        $count = $active->count();

        return $this->success([
            'active_count' => $count,
            // Populated ONLY when exactly one wave is active — the unambiguous current wave.
            'wave' => $count === 1 ? new PreparationWaveResource($active->first()) : null,
            // Lightweight identifiers for the picker and the multiple-active diagnostics (§6).
            'waves' => $active->map(fn (PreparationWave $w): array => [
                'id' => $w->id,
                'wave_number' => $w->wave_number,
                'planning_date' => $w->planning_date?->toDateString(),
                'status' => $w->status?->value,
            ])->values()->all(),
        ]);
    }

    public function store(CreateWaveRequest $request, CreateWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $this->authorize('create', PreparationWave::class);

        $validated = $request->validated();
        $companyId = $request->user()->company_id;
        $actorId = (string) $request->user()->id;
        $orderIds = $validated['order_ids'];

        $this->guardOrdersReservable($companyId, $orderIds);

        // Company-scoped even though guardOrdersReservable() has already refused any
        // foreign order: the snapshot below copies customer name, delivery zone and
        // shipping cost into the wave, so this query must not be permissive on its own.
        $orderLines = DB::table('orders')
            ->where('company_id', $companyId)
            ->whereIn('id', $orderIds)
            ->get(['id', 'order_number', 'confirmed_at', 'customer_name', 'delivery_zone',
                'governorate', 'shipping_cost', 'payment_status'])
            ->map(fn ($o) => [
                'order_id' => $o->id,
                'order_number' => $o->order_number ?? $o->id,
                'confirmed_at' => $o->confirmed_at ?? now()->toIso8601String(),
                'customer_name' => $o->customer_name ?? null,
                'delivery_zone' => $o->delivery_zone ?? null,
                'governorate' => $o->governorate ?? null,
                'shipping_cost' => $o->shipping_cost ?? null,
                'is_paid' => in_array($o->payment_status ?? '', ['paid', 'partially_paid'], true),
            ])
            ->toArray();

        $configVersionId = DB::table('configuration_versions')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->value('id');

        $dto = new CreateWaveDTO(
            companyId: $companyId,
            warehouseId: $validated['warehouse_id'],
            planningDate: $validated['planning_date'],
            orderLines: $orderLines,
            actorId: $actorId,
            brandId: $validated['brand_id'] ?? null,
            channelId: $validated['channel_id'] ?? null,
            configVersionId: $configVersionId,
            notes: $validated['notes'] ?? null,
        );

        $wave = $action->execute($dto);

        return $this->created(new PreparationWaveResource($wave));
    }

    public function show(Request $request, string $waveId): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id, [
            'waveOrders', 'waveItems', 'materialRequirements', 'exceptions', 'workers', 'pickList',
        ]);
        $this->authorize('view', $wave);

        return $this->success(new PreparationWaveResource($wave));
    }

    public function generateDemand(Request $request, string $waveId, GenerateDemandAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('generateDemand', $wave);

        $result = $action->execute($wave, (string) $request->user()->id);

        return $this->success(new PreparationWaveResource($result->load('waveItems')));
    }

    public function analyzeMaterials(Request $request, string $waveId, AnalyzeMaterialsAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('analyzeMaterials', $wave);

        $result = $action->execute($wave, (string) $request->user()->id);

        return $this->success(new PreparationWaveResource($result->load('materialRequirements')));
    }

    public function approve(ApproveWaveRequest $request, string $waveId, ApproveWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('approve', $wave);

        $result = $action->execute($wave, (string) $request->user()->id, $request->validated('notes'));

        return $this->success(new PreparationWaveResource($result));
    }

    public function start(StartPreparationRequest $request, string $waveId, StartPreparationAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id, ['waveItems']);
        $this->authorize('start', $wave);

        $validated = $request->validated();
        $workerIds = $validated['worker_ids'] ?? [];

        $workers = array_map(
            fn ($id) => ['user_id' => $id, 'role' => 'operator'],
            $workerIds,
        );

        if (! empty($validated['supervisor_id'])) {
            $workers[] = ['user_id' => $validated['supervisor_id'], 'role' => 'supervisor'];
        }

        $dto = new StartPreparationDTO(
            actorId: (string) $request->user()->id,
            workers: $workers,
            stationIds: $validated['station_ids'] ?? [],
            overrideShortage: (bool) ($validated['override_shortage'] ?? false),
        );
        $result = $action->execute($wave, $dto);

        return $this->success(new PreparationWaveResource($result->load(['pickList', 'workers'])));
    }

    public function advance(Request $request, string $waveId, WavePreparationService $service): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('start', $wave);

        $result = $service->startPreparation($wave, (string) $request->user()->id);

        return $this->success(new PreparationWaveResource($result));
    }

    public function completeItem(
        CompleteProductRequest $request,
        string $waveId,
        string $itemId,
        CompleteProductAction $action,
    ): JsonResponse {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('completeItem', $wave);

        $item = PreparationWaveItem::where('id', $itemId)
            ->where('preparation_wave_id', $wave->id)
            ->first();

        if (! $item) {
            throw WaveItemNotFoundException::forId($itemId);
        }

        $validated = $request->validated();
        $result = $action->execute(
            $wave,
            $item,
            (float) $validated['quantity_prepared'],
            (string) $request->user()->id,
            $validated['notes'] ?? null,
        );

        return $this->success([
            'id' => $result->id,
            'product_id' => $result->product_id,
            'sku' => $result->sku_snapshot,
            'quantity_required' => $result->quantity_required,
            'quantity_prepared' => $result->quantity_prepared,
            'quantity_short' => $result->quantity_short,
            'status' => $result->status?->value,
            'prepared_at' => $result->prepared_at?->toIso8601String(),
            'prepared_by' => $result->prepared_by,
        ]);
    }

    public function complete(Request $request, string $waveId, CompleteWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id, ['waveItems']);
        $this->authorize('complete', $wave);

        $result = $action->execute($wave, (string) $request->user()->id);

        return $this->success(new PreparationWaveResource($result));
    }

    public function cancel(CancelWaveRequest $request, string $waveId, CancelWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('cancel', $wave);

        $result = $action->execute($wave, (string) $request->user()->id, $request->validated('reason'));

        return $this->success([
            'id' => $result->id,
            'status' => $result->status?->value,
            'cancelled_at' => $result->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $result->cancellation_reason,
        ]);
    }

    /**
     * Postpone an order out of the CURRENT preparation cycle.
     *
     * Not a delete. The `preparation_wave_orders` row is retained with `postponed_at`
     * stamped, which both preserves the membership as history and keeps the collector's
     * `whereNotExists` satisfied so the every-minute scheduler cannot re-attach it. The
     * order, its lines, its customer and its status are untouched.
     *
     * Idempotent: postponing twice returns 200 with `postponed = false` and mutates nothing.
     */
    public function postponeOrder(
        Request $request,
        string $waveId,
        string $orderId,
        WaveMembershipService $membership,
    ): JsonResponse {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('postponeOrder', $wave);

        $postponed = $membership->postponeOrder($wave, $orderId, (string) $request->user()->id);

        return $this->success([
            'order_id' => $orderId,
            'wave_id' => $wave->id,
            'postponed' => $postponed,
        ], $postponed ? 'Order postponed from the current preparation cycle.' : 'Order was already postponed.');
    }

    public function recalculate(RecalculateWaveRequest $request, string $waveId, RecalculateWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('recalculate', $wave);

        $validated = $request->validated();
        $actorId = (string) $request->user()->id;

        $addOrderLines = [];
        if (! empty($validated['add_order_ids'])) {
            // Same entry gate as store(). Runtime certification proved this route
            // also attached an awaiting_stock order (HTTP 200, 1 row) because it
            // performed no eligibility or company check at all.
            $this->guardOrdersReservable($wave->company_id, $validated['add_order_ids']);

            $addOrderLines = DB::table('orders')
                ->where('company_id', $wave->company_id)
                ->whereIn('id', $validated['add_order_ids'])
                ->get(['id', 'order_number', 'confirmed_at', 'customer_name', 'delivery_zone'])
                ->map(fn ($o) => [
                    'order_id' => $o->id,
                    'order_number' => $o->order_number ?? $o->id,
                    'confirmed_at' => $o->confirmed_at ?? now()->toIso8601String(),
                    'customer_name' => $o->customer_name ?? null,
                    'delivery_zone' => $o->delivery_zone ?? null,
                ])
                ->toArray();
        }

        $dto = new RecalculateWaveDTO(
            actorId: $actorId,
            removeOrderIds: $validated['remove_order_ids'] ?? [],
            addOrderLines: $addOrderLines,
        );
        $result = $action->execute($wave, $dto);

        return $this->success(new PreparationWaveResource($result->load('waveItems')));
    }

    public function productQueue(Request $request, string $waveId): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('view', $wave);

        $request->validate([
            'status' => ['nullable', 'string'],
            'sort' => ['nullable', 'string'],
        ]);

        $statusFilter = $request->query('status');

        $items = DB::table('preparation_wave_items as pwi')
            ->join('products as p', 'p.id', '=', 'pwi.product_id')
            ->join('units as u', 'u.id', '=', 'p.unit_id')
            ->leftJoin(
                DB::raw('(SELECT bom.product_id, COUNT(*) as material_count FROM bills_of_materials bom JOIN bill_of_material_lines boml ON boml.bom_id = bom.id WHERE bom.is_active = 1 GROUP BY bom.product_id) as bom_summary'),
                'bom_summary.product_id', '=', 'pwi.product_id',
            )
            ->leftJoin(
                DB::raw('(SELECT ol.product_id, COUNT(DISTINCT ol.order_id) as orders_count FROM order_lines ol JOIN preparation_wave_orders pwo ON pwo.order_id = ol.order_id WHERE pwo.preparation_wave_id = '.DB::getPdo()->quote($wave->id).' GROUP BY ol.product_id) as order_summary'),
                'order_summary.product_id', '=', 'pwi.product_id',
            )
            ->where('pwi.preparation_wave_id', $wave->id)
            ->when($statusFilter, fn ($q) => $q->where('pwi.status', $statusFilter))
            ->select([
                'pwi.id',
                'pwi.product_id',
                'pwi.sku_snapshot as sku',
                'pwi.name_snapshot as name',
                'pwi.quantity_required',
                'pwi.quantity_prepared',
                'pwi.quantity_short',
                'pwi.status',
                'pwi.prepared_at',
                'pwi.prepared_by',
                'p.image_url as thumbnail_url',
                'p.stock_status',
                'u.symbol as unit_symbol',
                DB::raw('COALESCE(bom_summary.material_count, 0) as material_count'),
                DB::raw('CASE WHEN bom_summary.product_id IS NOT NULL THEN 1 ELSE 0 END as has_recipe'),
                DB::raw('COALESCE(order_summary.orders_count, 0) as orders_count'),
            ])
            ->get();

        return $this->success($items->map(fn ($i) => [
            'id' => $i->id,
            'product_id' => $i->product_id,
            'sku' => $i->sku,
            'name' => $i->name,
            'thumbnail_url' => $i->thumbnail_url,
            'stock_status' => $i->stock_status,
            'unit_symbol' => $i->unit_symbol,
            'quantity_required' => (float) $i->quantity_required,
            'quantity_prepared' => (float) $i->quantity_prepared,
            'quantity_short' => (float) $i->quantity_short,
            'completion_pct' => $i->quantity_required > 0
                ? round(($i->quantity_prepared / $i->quantity_required) * 100, 1)
                : 0.0,
            'status' => $i->status,
            'has_recipe' => (bool) $i->has_recipe,
            'material_count' => (int) $i->material_count,
            'orders_count' => (int) $i->orders_count,
            'prepared_at' => $i->prepared_at,
            'prepared_by' => $i->prepared_by,
        ])->values()->all());
    }

    public function productWorkspace(Request $request, string $waveId, string $itemId): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('view', $wave);

        $item = PreparationWaveItem::where('id', $itemId)
            ->where('preparation_wave_id', $wave->id)
            ->first();

        if (! $item) {
            throw WaveItemNotFoundException::forId($itemId);
        }

        $product = DB::table('products as p')
            ->join('units as u', 'u.id', '=', 'p.unit_id')
            ->where('p.id', $item->product_id)
            ->select(['p.id', 'p.sku', 'p.name', 'p.image_url', 'p.stock_status', 'u.id as unit_id', 'u.name as unit_name', 'u.symbol as unit_symbol'])
            ->first();

        $bom = DB::table('bills_of_materials')
            ->where('product_id', $item->product_id)
            ->where('is_active', true)
            ->first();

        $recipe = null;
        if ($bom) {
            $lines = DB::table('bill_of_material_lines as boml')
                ->join('raw_materials as rm', 'rm.id', '=', 'boml.raw_material_id')
                ->leftJoin('units as u', 'u.id', '=', 'rm.unit_id')
                ->where('boml.bom_id', $bom->id)
                ->select([
                    'boml.id',
                    'boml.raw_material_id',
                    'rm.name as material_name',
                    'rm.sku as material_sku',
                    'boml.quantity',
                    'boml.waste_percentage',
                    'u.symbol as unit_symbol',
                ])
                ->get();

            $recipe = [
                'bom_id' => $bom->id,
                'recipe_cost' => $bom->recipe_cost ?? null,
                'material_lines' => $lines->map(fn ($l) => [
                    'id' => $l->id,
                    'raw_material_id' => $l->raw_material_id,
                    'material_name' => $l->material_name,
                    'material_sku' => $l->material_sku,
                    'quantity' => (float) $l->quantity,
                    'waste_percentage' => (float) $l->waste_percentage,
                    'unit_symbol' => $l->unit_symbol,
                ])->values()->all(),
            ];
        }

        $materialRequirements = DB::table('preparation_material_requirements')
            ->where('preparation_wave_id', $wave->id)
            ->whereExists(function ($q) use ($bom) {
                if (! $bom) {
                    $q->selectRaw('0')->whereRaw('1 = 0');

                    return;
                }
                $q->select(DB::raw(1))
                    ->from('bill_of_material_lines')
                    ->where('bom_id', $bom->id)
                    ->whereColumn('bill_of_material_lines.raw_material_id', 'preparation_material_requirements.raw_material_id');
            })
            ->get();

        $orders = DB::table('order_lines as ol')
            ->join('orders as o', 'o.id', '=', 'ol.order_id')
            ->join('preparation_wave_orders as pwo', 'pwo.order_id', '=', 'o.id')
            ->where('pwo.preparation_wave_id', $wave->id)
            ->where('ol.product_id', $item->product_id)
            ->select(['o.id as order_id', 'o.order_number', 'ol.quantity', 'o.customer_name', 'o.delivery_zone', 'o.status as order_status'])
            ->get();

        return $this->success([
            'item' => [
                'id' => $item->id,
                'product_id' => $item->product_id,
                'sku' => $item->sku_snapshot,
                'name' => $item->name_snapshot,
                'quantity_required' => $item->quantity_required,
                'quantity_prepared' => $item->quantity_prepared,
                'quantity_short' => $item->quantity_short,
                'status' => $item->status?->value,
                'prepared_at' => $item->prepared_at?->toIso8601String(),
                'prepared_by' => $item->prepared_by,
            ],
            'product' => $product,
            'recipe' => $recipe,
            'materials' => $materialRequirements->map(fn ($mr) => [
                'id' => $mr->id,
                'raw_material_id' => $mr->raw_material_id,
                'quantity_needed' => (float) $mr->quantity_needed,
                'quantity_on_hand' => (float) ($mr->quantity_on_hand ?? 0),
                'shortage_qty' => (float) ($mr->shortage_qty ?? 0),
                'shortage_flag' => (bool) ($mr->shortage_flag ?? false),
                'status' => $mr->status ?? null,
            ])->values()->all(),
            'orders' => $orders->map(fn ($o) => [
                'order_id' => $o->order_id,
                'order_number' => $o->order_number,
                'quantity' => (float) $o->quantity,
                'customer_name' => $o->customer_name,
                'delivery_zone' => $o->delivery_zone,
                'order_status' => $o->order_status,
            ])->values()->all(),
        ]);
    }

    public function reportIssue(ReportIssueRequest $request, string $waveId, ReportIssueAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('view', $wave);

        $validated = $request->validated();

        $dto = new ReportIssueDTO(
            waveId: $wave->id,
            companyId: $request->user()->company_id,
            actorId: (string) $request->user()->id,
            issueType: PreparationIssueType::from($validated['issue_type']),
            description: $validated['description'],
            entityType: $validated['entity_type'] ?? null,
            entityId: $validated['entity_id'] ?? null,
        );

        $exception = $action->execute($dto);

        return $this->created([
            'id' => $exception->id,
            'issue_type' => $exception->issue_type?->value,
            'severity' => $exception->severity?->value,
            'description' => $exception->description,
            'status' => $exception->status?->value,
            'raised_at' => $exception->raised_at?->toIso8601String(),
            'raised_by' => $exception->raised_by,
        ]);
    }

    public function assignWorker(
        AssignWorkerRequest $request,
        string $waveId,
        AssignWorkerAction $action,
    ): JsonResponse {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('assignWorker', $wave);

        $validated = $request->validated();
        $worker = $action->execute(
            $wave,
            (string) $validated['user_id'],
            $validated['role'],
            (string) (string) $request->user()->id,
        );

        return $this->created([
            'id' => $worker->id,
            'user_id' => $worker->user_id,
            'role' => $worker->role,
            'assigned_at' => $worker->assigned_at?->toIso8601String(),
        ]);
    }

    public function releaseWorker(
        ReleaseWorkerRequest $request,
        string $waveId,
        string $userId,
        ReleaseWorkerAction $action,
    ): JsonResponse {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('releaseWorker', $wave);

        $action->execute($wave, $userId, (string) (string) $request->user()->id);

        return $this->success(['message' => 'Worker released successfully.']);
    }

    public function resolveShortage(
        ResolveShortageRequest $request,
        string $waveId,
        ResolveShortageAction $action,
    ): JsonResponse {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('resolveShortage', $wave);

        $validated = $request->validated();
        $result = $action->execute(
            $wave,
            (string) (string) $request->user()->id,
            $validated['requirement_ids'] ?? [],
            $validated['resolution_notes'] ?? null,
        );

        return $this->success(new PreparationWaveResource($result));
    }

    public function timeline(Request $request, string $waveId, TimelineService $timeline): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('view', $wave);

        $entries = $timeline->getFor('PreparationWave', $waveId);

        return $this->success($entries->map(fn ($e) => [
            'id' => $e->id,
            'event_type' => $e->event_type,
            'title' => $e->title,
            'description' => $e->description,
            'actor_id' => $e->actor_id,
            'actor_name' => $e->actor_name,
            'source_module' => $e->source_module,
            'occurred_at' => $e->occurred_at?->toIso8601String(),
            'metadata' => $e->metadata,
        ])->values()->all());
    }

    public function documents(Request $request, string $waveId, DocumentService $documents): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('view', $wave);

        $docs = $documents->getFor('PreparationWave', $waveId);

        return $this->success($docs->map(fn ($d) => [
            'id' => $d->id,
            'document_type' => $d->document_type,
            'name' => $d->name,
            'mime_type' => $d->mime_type,
            'file_size' => $d->file_size,
            'version' => $d->version,
            'notes' => $d->notes,
            'uploaded_by' => $d->uploaded_by,
            'created_at' => $d->created_at?->toIso8601String(),
        ])->values()->all());
    }

    private function findWave(string $waveId, string $companyId, array $relations = []): PreparationWave
    {
        $query = PreparationWave::where('id', $waveId)->where('company_id', $companyId);

        if (! empty($relations)) {
            $query->with($relations);
        }

        $wave = $query->first();

        if (! $wave) {
            throw WaveNotFoundException::forId($waveId);
        }

        return $wave;
    }

    /**
     * The Preparation entry gate for the wave routes.
     *
     * TASK-GOLIVE-PREPARATION-ENTRY-GATE-REPAIR-002.
     *
     * Runtime certification proved that BOTH wave entry routes accepted orders the
     * rest of the module refuses: an order persisted as `awaiting_stock` with
     * `reserved_qty = 0` was attached to a wave (HTTP 201), and so was an order
     * belonging to another company — stamped with the ACTOR's company_id.
     *
     * The cause was that this method only ever checked wave membership, and the
     * orders were loaded with no company predicate. Meanwhile
     * OrderPreparationObserver, DailyPreparationSessionManager and
     * WarehouseAssignedListener all already delegate to PreparationReleaseEngine,
     * which documents itself as "the ONLY authority that decides whether an order
     * may enter (or remain in) a Preparation Session". The wave routes were the
     * sole paths bypassing it.
     *
     * This method now enforces, IN ORDER and strictly BEFORE any mutation:
     *   1. Existence + company ownership — an id that is not this company's order
     *      is refused. Absence is treated as refusal, never as unrestricted access.
     *   2. The authoritative status policy, via PreparationReleaseEngine — no
     *      status check is duplicated here.
     *   3. Wave exclusivity — the pre-existing rule, unchanged.
     *
     * Reservation state is deliberately NOT consulted: eligibility is a function of
     * order status, not of reserved quantity. A reserved order in a post-Preparation
     * state is still refused.
     *
     * @param  list<string>  $orderIds
     */
    private function guardOrdersReservable(string $companyId, array $orderIds): void
    {
        // 1 — Existence and tenant ownership. Company-scoped lookup: an order id
        //     belonging to another company simply does not resolve here.
        $orders = Order::query()
            ->where('company_id', $companyId)
            ->whereIn('id', $orderIds)
            ->get()
            ->keyBy('id');

        $unknown = array_values(array_diff($orderIds, $orders->keys()->all()));

        if (! empty($unknown)) {
            abort(422, 'One or more orders do not exist or do not belong to this company.', [
                'code' => 'order_not_in_company',
                'order_ids' => $unknown,
            ]);
        }

        // 2 — Authoritative status policy. Reused, never reimplemented.
        $ineligible = [];

        foreach ($orders as $order) {
            $policy = $this->releaseEngine->resolvePolicy($companyId, $order->assigned_warehouse_id);
            $reason = $this->releaseEngine->ineligibilityReason($order, $policy);

            if ($reason !== null) {
                $ineligible[$order->id] = $reason;
            }
        }

        if (! empty($ineligible)) {
            abort(422, 'One or more orders are not eligible for Preparation.', [
                'code' => 'order_not_eligible_for_preparation',
                'orders' => $ineligible,
            ]);
        }

        // 3 — Wave exclusivity: ACTIVE membership only (PART 15).
        //
        // `released_at IS NULL` replaces the wave-status test as the authority. The old
        // status list also had a hole: `closed` was absent from it, so an order whose
        // wave had been closed by the engine was still reported as "already in an active
        // wave" and could never be added to another one. Membership state answers that
        // question directly and does not depend on a wave status that the audit proved
        // unreliable.
        $alreadyInWave = DB::table('preparation_wave_orders as pwo')
            ->join('preparation_waves as pw', 'pw.id', '=', 'pwo.preparation_wave_id')
            ->where('pw.company_id', $companyId)
            ->whereIn('pwo.order_id', $orderIds)
            ->whereNull('pwo.released_at')
            ->whereNotIn('pw.status', ['completed', 'cancelled'])
            ->pluck('pwo.order_id')
            ->toArray();

        if (! empty($alreadyInWave)) {
            abort(422, 'One or more orders are already in an active wave.', ['code' => 'order_already_in_wave']);
        }
    }

    private function guardModuleEnabled(?string $companyId): void
    {
        if ($this->flags->isDisabled('modules.preparation_os', $companyId)) {
            abort(503, 'Preparation OS module is not enabled for this company.');
        }
    }
}
