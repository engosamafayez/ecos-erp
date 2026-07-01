<?php

declare(strict_types=1);

namespace Modules\POS\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\POS\Application\Commands\CloseSessionCommand;
use Modules\POS\Application\Commands\OpenSessionCommand;
use Modules\POS\Application\Services\CloseSessionService;
use Modules\POS\Application\Services\FindSessionService;
use Modules\POS\Application\Services\OpenSessionService;
use Modules\POS\Presentation\Http\Requests\OpenSessionRequest;
use Modules\POS\Presentation\Http\Resources\SessionResource;
use Modules\POS\Session\Domain\ValueObjects\DeviceFingerprint;

final class SessionController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly OpenSessionService  $openSessionService,
        private readonly FindSessionService  $findSessionService,
        private readonly CloseSessionService $closeSessionService,
    ) {}

    public function store(OpenSessionRequest $request): JsonResponse
    {
        $data    = $request->validated();
        $command = new OpenSessionCommand(
            terminalId:        $data['terminal_id'],
            cashierId:         $data['cashier_id'],
            deviceFingerprint: $data['device_fingerprint'],
            ipAddress:         $data['ip_address'],
            deviceType:        $data['device_type'],
        );

        $result = $this->openSessionService->execute($command);

        $session = $this->findSessionService->execute($result->sessionId);

        return $this->created(new SessionResource($session), 'Session opened.');
    }

    public function show(string $session): JsonResponse
    {
        $model = $this->findSessionService->execute($session);

        return $this->success(new SessionResource($model));
    }

    public function destroy(string $session): JsonResponse
    {
        $model   = $this->findSessionService->execute($session);
        $command = new CloseSessionCommand(
            sessionId: (string) $model->id,
            cashierId: (string) $model->cashier_id,
        );

        $this->closeSessionService->execute($command);

        return $this->deleted('Session closed.');
    }
}
