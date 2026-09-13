<?php

declare(strict_types=1);

namespace Modules\Commerce\Connectors\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Commerce\Channels\Application\Actions\GeneratePairingCodeAction;
use Modules\Commerce\Channels\Presentation\Http\Resources\ChannelResource;
use Modules\Commerce\Connectors\Application\Actions\TestConnectionAction;

final class ConnectorController extends Controller
{
    use HasApiResponse;

    public function testConnection(string $channel, TestConnectionAction $action): JsonResponse
    {
        $result = $action->execute($channel);

        return $this->success(new ChannelResource($result->data()), $result->message());
    }

    /**
     * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 §14 — an ECOS operator generates a short-lived
     * pairing code for the merchant to paste into the WooCommerce Connector plugin.
     */
    public function generatePairingCode(string $channel, GeneratePairingCodeAction $action): JsonResponse
    {
        $result = $action->execute($channel);

        return $this->success($result->data(), $result->message());
    }
}
