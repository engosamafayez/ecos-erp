<?php

declare(strict_types=1);

namespace Modules\Logistics\Drivers\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Modules\Logistics\Drivers\Domain\Models\Vehicle;
use Modules\Logistics\Drivers\Presentation\Http\Resources\VehicleResource;

/**
 * Minimal vehicle registry — only what driver assignment needs.
 *
 * Scope note: the full Vehicles module (TASK-LOG-003) will own maintenance,
 * insurance, ownership and telemetry. It should extend this controller and its
 * table rather than introduce a competing vehicle aggregate.
 */
class VehicleController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Vehicle::with(['shippingCompany', 'activeAssignment.driver'])
            ->orderBy('plate_number');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('plate_number', 'like', "%{$search}%")
                    ->orWhere('make', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%");
            });
        }

        if ($request->filled('type') && in_array($request->input('type'), Vehicle::TYPES, true)) {
            $query->where('type', $request->input('type'));
        }

        $status = $request->input('status');
        if ($status && in_array($status, Vehicle::STATUSES, true)) {
            $query->where('status', $status);
        } elseif ($status !== 'all') {
            $query->where('status', '!=', Vehicle::STATUS_ARCHIVED);
        }

        // available=1 restricts to vehicles free to be assigned — the picker
        // in the driver drawer uses this so a taken vehicle is never offered.
        if ($request->boolean('available')) {
            $query->where('status', Vehicle::STATUS_ACTIVE)
                ->whereDoesntHave('assignments', fn ($q) => $q->whereNotNull('active_flag'));
        }

        $perPage = min((int) $request->input('per_page', 50), 100);

        return VehicleResource::collection($query->paginate($perPage));
    }

    public function show(int $id): VehicleResource
    {
        return new VehicleResource(
            Vehicle::with(['shippingCompany', 'activeAssignment.driver'])->findOrFail($id)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $vehicle = Vehicle::create($validated);

        return (new VehicleResource($vehicle->load('shippingCompany')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, int $id): VehicleResource
    {
        $vehicle = Vehicle::findOrFail($id);

        $vehicle->update($request->validate($this->rules($vehicle->id, partial: true)));

        return new VehicleResource($vehicle->load(['shippingCompany', 'activeAssignment.driver']));
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?int $ignoreId = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'plate_number' => [
                $required, 'string', 'max:30',
                Rule::unique('logistics_vehicles', 'plate_number')->ignore($ignoreId),
            ],
            'type' => [$required, Rule::in(Vehicle::TYPES)],
            'make' => ['nullable', 'string', 'max:50'],
            'model' => ['nullable', 'string', 'max:50'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'capacity_orders' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'shipping_company_id' => ['nullable', 'integer', 'exists:logistics_shipping_companies,id'],
            'status' => ['sometimes', Rule::in(Vehicle::STATUSES)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
