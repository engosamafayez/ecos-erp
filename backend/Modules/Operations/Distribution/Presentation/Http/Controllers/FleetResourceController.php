<?php

declare(strict_types=1);

namespace Modules\Operations\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Distribution\Domain\Models\ExternalCarrier;
use Modules\Operations\Distribution\Domain\Models\FleetDriver;
use Modules\Operations\Distribution\Domain\Models\FleetVehicle;

class FleetResourceController extends Controller
{
    public function vehicles(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $vehicles = FleetVehicle::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNotIn('status', ['retired'])
            ->orderBy('plate_number')
            ->get(['id', 'plate_number', 'type', 'make', 'model', 'year', 'capacity_orders', 'status']);

        return response()->json(['vehicles' => $vehicles]);
    }

    public function drivers(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $drivers = FleetDriver::where('company_id', $companyId)
            ->where('is_active', true)
            ->whereNotIn('status', ['inactive'])
            ->orderBy('name_en')
            ->get(['id', 'name_en', 'name_ar', 'phone', 'status']);

        return response()->json(['drivers' => $drivers]);
    }

    public function carriers(Request $request): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $carriers = ExternalCarrier::where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'contact_person', 'phone', 'rate_per_order']);

        return response()->json(['carriers' => $carriers]);
    }
}
