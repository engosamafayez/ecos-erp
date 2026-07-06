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
use Modules\Operations\Preparation\Application\Actions\ResolveShortageAction;
use Modules\Operations\Preparation\Application\Actions\StartPreparationAction;
use Modules\Operations\Preparation\Application\DTOs\CreateWaveDTO;
use Modules\Operations\Preparation\Application\DTOs\RecalculateWaveDTO;
use Modules\Operations\Preparation\Application\DTOs\StartPreparationDTO;
use Modules\Operations\Preparation\Domain\Exceptions\InvalidWaveStatusTransitionException;
use Modules\Operations\Preparation\Domain\Exceptions\ShortageNotResolvedException;
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
use Modules\Operations\Preparation\Presentation\Http\Requests\ResolveShortageRequest;
use Modules\Operations\Preparation\Presentation\Http\Requests\StartPreparationRequest;
use Modules\Operations\Preparation\Presentation\Http\Resources\PreparationWaveResource;

final class PreparationWaveController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly FeatureFlagService $flags) {}

    public function index(Request $request): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $this->authorize('viewAny', PreparationWave::class);

        $request->validate([
            'status'        => ['nullable', 'string'],
            'warehouse_id'  => ['nullable', 'uuid'],
            'planning_date' => ['nullable', 'date_format:Y-m-d'],
            'search'        => ['nullable', 'string', 'max:100'],
            'page'          => ['nullable', 'integer', 'min:1'],
            'per_page'      => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort'          => ['nullable', 'string'],
        ]);

        $companyId = $request->user()->company_id;
        $perPage   = (int) ($request->query('per_page', 25));

        $sortable = ['wave_number', 'planning_date', 'status', 'orders_count', 'created_at'];
        $sortRaw  = $request->query('sort', '-created_at');
        $desc     = str_starts_with((string) $sortRaw, '-');
        $sortCol  = ltrim((string) $sortRaw, '-');
        $sortCol  = in_array($sortCol, $sortable, true) ? $sortCol : 'created_at';

        $query = PreparationWave::with(['workers' => fn ($q) => $q->whereNull('released_at')])
            ->where('company_id', $companyId)
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->query('planning_date'), fn ($q, $v) => $q->whereDate('planning_date', $v))
            ->when($request->query('search'), fn ($q, $v) => $q->where('wave_number', 'ilike', "%{$v}%"))
            ->orderBy($sortCol, $desc ? 'desc' : 'asc');

        $paginator = $query->paginate($perPage);

        return $this->success([
            'data' => PreparationWaveResource::collection($paginator->items()),
            'meta' => [
                'page'      => $paginator->currentPage(),
                'per_page'  => $paginator->perPage(),
                'total'     => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(CreateWaveRequest $request, CreateWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $this->authorize('create', PreparationWave::class);

        $validated  = $request->validated();
        $companyId  = $request->user()->company_id;
        $actorId    = $request->user()->id;
        $orderIds   = $validated['order_ids'];

        $this->guardOrdersReservable($companyId, $orderIds);

        $orderLines = DB::table('orders')
            ->whereIn('id', $orderIds)
            ->get(['id', 'order_number', 'confirmed_at', 'customer_name', 'delivery_zone'])
            ->map(fn ($o) => [
                'order_id'      => $o->id,
                'order_number'  => $o->order_number ?? $o->id,
                'confirmed_at'  => $o->confirmed_at ?? now()->toIso8601String(),
                'customer_name' => $o->customer_name ?? null,
                'delivery_zone' => $o->delivery_zone ?? null,
            ])
            ->toArray();

        $configVersionId = DB::table('configuration_versions')
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->value('id');

        $dto  = new CreateWaveDTO(
            companyId:       $companyId,
            warehouseId:     $validated['warehouse_id'],
            planningDate:    $validated['planning_date'],
            orderLines:      $orderLines,
            actorId:         $actorId,
            configVersionId: $configVersionId,
            notes:           $validated['notes'] ?? null,
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

        $result = $action->execute($wave, $request->user()->id);

        return $this->success(new PreparationWaveResource($result->load('waveItems')));
    }

    public function analyzeMaterials(Request $request, string $waveId, AnalyzeMaterialsAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('analyzeMaterials', $wave);

        $result = $action->execute($wave, $request->user()->id);

        return $this->success(new PreparationWaveResource($result->load('materialRequirements')));
    }

    public function approve(ApproveWaveRequest $request, string $waveId, ApproveWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('approve', $wave);

        $result = $action->execute($wave, $request->user()->id, $request->validated('notes'));

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
            $workerIds
        );

        if (! empty($validated['supervisor_id'])) {
            $workers[] = ['user_id' => $validated['supervisor_id'], 'role' => 'supervisor'];
        }

        $dto    = new StartPreparationDTO(
            actorId:          $request->user()->id,
            workers:          $workers,
            stationIds:       $validated['station_ids'] ?? [],
            overrideShortage: (bool) ($validated['override_shortage'] ?? false),
        );
        $result = $action->execute($wave, $dto);

        return $this->success(new PreparationWaveResource($result->load(['pickList', 'workers'])));
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
        $result    = $action->execute(
            $wave,
            $item,
            (float) $validated['quantity_prepared'],
            $request->user()->id,
            $validated['notes'] ?? null,
        );

        return $this->success([
            'id'                => $result->id,
            'product_id'        => $result->product_id,
            'sku'               => $result->sku_snapshot,
            'quantity_required' => $result->quantity_required,
            'quantity_prepared' => $result->quantity_prepared,
            'quantity_short'    => $result->quantity_short,
            'status'            => $result->status?->value,
            'prepared_at'       => $result->prepared_at?->toIso8601String(),
            'prepared_by'       => $result->prepared_by,
        ]);
    }

    public function complete(Request $request, string $waveId, CompleteWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id, ['waveItems']);
        $this->authorize('complete', $wave);

        $result = $action->execute($wave, $request->user()->id);

        return $this->success(new PreparationWaveResource($result));
    }

    public function cancel(CancelWaveRequest $request, string $waveId, CancelWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('cancel', $wave);

        $result = $action->execute($wave, $request->user()->id, $request->validated('reason'));

        return $this->success([
            'id'                  => $result->id,
            'status'              => $result->status?->value,
            'cancelled_at'        => $result->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $result->cancellation_reason,
        ]);
    }

    public function recalculate(RecalculateWaveRequest $request, string $waveId, RecalculateWaveAction $action): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('recalculate', $wave);

        $validated = $request->validated();
        $actorId   = $request->user()->id;

        $addOrderLines = [];
        if (! empty($validated['add_order_ids'])) {
            $addOrderLines = DB::table('orders')
                ->whereIn('id', $validated['add_order_ids'])
                ->get(['id', 'order_number', 'confirmed_at', 'customer_name', 'delivery_zone'])
                ->map(fn ($o) => [
                    'order_id'      => $o->id,
                    'order_number'  => $o->order_number ?? $o->id,
                    'confirmed_at'  => $o->confirmed_at ?? now()->toIso8601String(),
                    'customer_name' => $o->customer_name ?? null,
                    'delivery_zone' => $o->delivery_zone ?? null,
                ])
                ->toArray();
        }

        $dto    = new RecalculateWaveDTO(
            actorId:        $actorId,
            removeOrderIds: $validated['remove_order_ids'] ?? [],
            addOrderLines:  $addOrderLines,
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
            'sort'   => ['nullable', 'string'],
        ]);

        $items = $wave->waveItems()
            ->when($request->query('status'), fn ($q, $v) => $q->where('status', $v))
            ->get();

        return $this->success($items->map(fn ($i) => [
            'id'                => $i->id,
            'product_id'        => $i->product_id,
            'sku'               => $i->sku_snapshot,
            'name'              => $i->name_snapshot,
            'thumbnail_url'     => null,
            'quantity_required' => $i->quantity_required,
            'quantity_prepared' => $i->quantity_prepared,
            'quantity_short'    => $i->quantity_short,
            'completion_pct'    => $i->completionPct(),
            'status'            => $i->status?->value,
        ])->values()->all());
    }

    public function assignWorker(
        AssignWorkerRequest $request,
        string              $waveId,
        AssignWorkerAction  $action,
    ): JsonResponse {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('assignWorker', $wave);

        $validated = $request->validated();
        $worker    = $action->execute(
            $wave,
            (string) $validated['user_id'],
            $validated['role'],
            (string) $request->user()->id,
        );

        return $this->created([
            'id'          => $worker->id,
            'user_id'     => $worker->user_id,
            'role'        => $worker->role,
            'assigned_at' => $worker->assigned_at?->toIso8601String(),
        ]);
    }

    public function releaseWorker(
        ReleaseWorkerRequest $request,
        string               $waveId,
        string               $userId,
        ReleaseWorkerAction  $action,
    ): JsonResponse {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('releaseWorker', $wave);

        $action->execute($wave, $userId, (string) $request->user()->id);

        return $this->success(['message' => 'Worker released successfully.']);
    }

    public function resolveShortage(
        ResolveShortageRequest $request,
        string                 $waveId,
        ResolveShortageAction  $action,
    ): JsonResponse {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('resolveShortage', $wave);

        $validated = $request->validated();
        $result    = $action->execute(
            $wave,
            (string) $request->user()->id,
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
            'id'            => $e->id,
            'event_type'    => $e->event_type,
            'title'         => $e->title,
            'description'   => $e->description,
            'actor_id'      => $e->actor_id,
            'actor_name'    => $e->actor_name,
            'source_module' => $e->source_module,
            'occurred_at'   => $e->occurred_at?->toIso8601String(),
            'metadata'      => $e->metadata,
        ])->values()->all());
    }

    public function documents(Request $request, string $waveId, DocumentService $documents): JsonResponse
    {
        $this->guardModuleEnabled($request->user()?->company_id);
        $wave = $this->findWave($waveId, $request->user()->company_id);
        $this->authorize('view', $wave);

        $docs = $documents->getFor('PreparationWave', $waveId);

        return $this->success($docs->map(fn ($d) => [
            'id'            => $d->id,
            'document_type' => $d->document_type,
            'name'          => $d->name,
            'mime_type'     => $d->mime_type,
            'file_size'     => $d->file_size,
            'version'       => $d->version,
            'notes'         => $d->notes,
            'uploaded_by'   => $d->uploaded_by,
            'created_at'    => $d->created_at?->toIso8601String(),
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

    /** @param list<string> $orderIds */
    private function guardOrdersReservable(string $companyId, array $orderIds): void
    {
        $alreadyInWave = DB::table('preparation_wave_orders as pwo')
            ->join('preparation_waves as pw', 'pw.id', '=', 'pwo.preparation_wave_id')
            ->where('pw.company_id', $companyId)
            ->whereIn('pwo.order_id', $orderIds)
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
