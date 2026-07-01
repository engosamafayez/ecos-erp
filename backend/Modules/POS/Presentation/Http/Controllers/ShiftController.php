<?php

declare(strict_types=1);

namespace Modules\POS\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\POS\Application\Commands\CloseShiftCommand;
use Modules\POS\Application\Commands\OpenShiftCommand;
use Modules\POS\Application\Services\CloseShiftService;
use Modules\POS\Application\Services\FindShiftService;
use Modules\POS\Application\Services\OpenShiftService;
use Modules\POS\Presentation\Http\Requests\CloseShiftRequest;
use Modules\POS\Presentation\Http\Requests\OpenShiftRequest;
use Modules\POS\Presentation\Http\Resources\ShiftResource;

final class ShiftController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly OpenShiftService  $openShiftService,
        private readonly FindShiftService  $findShiftService,
        private readonly CloseShiftService $closeShiftService,
    ) {}

    public function store(OpenShiftRequest $request): JsonResponse
    {
        $data    = $request->validated();
        $command = new OpenShiftCommand(
            sessionId:           $data['session_id'],
            terminalId:          $data['terminal_id'],
            cashierId:           $data['cashier_id'],
            openingCashAmount:   (string) $data['opening_cash']['amount'],
            openingCashCurrency: $data['opening_cash']['currency'],
        );

        $result = $this->openShiftService->execute($command);
        $shift  = $this->findShiftService->execute($result->shiftId);

        return $this->created(new ShiftResource($shift), 'Shift opened.');
    }

    public function show(string $shift): JsonResponse
    {
        $model = $this->findShiftService->execute($shift);

        return $this->success(new ShiftResource($model));
    }

    public function destroy(string $shift, CloseShiftRequest $request): JsonResponse
    {
        $data    = $request->validated();
        $command = new CloseShiftCommand(
            shiftId:              $shift,
            closingCountAmount:   (string) $data['closing_count']['amount'],
            closingCountCurrency: $data['closing_count']['currency'],
        );

        $this->closeShiftService->execute($command);

        return $this->deleted('Shift closed.');
    }
}
