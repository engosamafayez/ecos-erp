<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Commerce\Channels\Application\Actions\AcknowledgeShippingMappingAction;
use Modules\Commerce\Channels\Application\Actions\DisableChannelAction;
use Modules\Commerce\Channels\Application\Actions\PauseChannelAction;
use Modules\Commerce\Channels\Application\Actions\ReenableChannelAction;
use Modules\Commerce\Channels\Application\Actions\ResumeChannelAction;
use Modules\Commerce\Channels\Application\Actions\TransitionChannelToLiveAction;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;
use Modules\Commerce\Channels\Domain\Services\ChannelGoLiveReadinessService;
use Modules\Commerce\Channels\Presentation\Http\Resources\ChannelResource;

/**
 * TASK-ECOS-V1.1-WOO-04-GO-LIVE-LIFECYCLE — thin HTTP wrapper, mirroring
 * OrdersSyncControlController's own precedent: all decisions live in the Actions/Service; this
 * controller only adapts the request and returns their result.
 */
final class ChannelLifecycleController extends Controller
{
    use HasApiResponse;

    public function readiness(
        string $channel,
        ChannelRepositoryInterface $channels,
        ChannelGoLiveReadinessService $readiness,
    ): JsonResponse {
        $model = $channels->findById($channel);

        if ($model === null) {
            throw new ChannelNotFoundException($channel);
        }

        // CTO source-review closure item A — reading readiness also keeps the persisted
        // DRAFT/CONFIGURED/READY label coherent with current data; LIVE/PAUSED/DISABLED are
        // never touched by this (see refreshPreLiveState()'s own docblock).
        $model = $readiness->refreshPreLiveState($model);

        return $this->success([
            'channel' => new ChannelResource($model),
            ...$readiness->assess($model),
        ]);
    }

    public function goLive(string $channel, TransitionChannelToLiveAction $action): JsonResponse
    {
        $result = $action->execute($channel);

        return $this->success(new ChannelResource($result->data()), $result->message());
    }

    public function pause(string $channel, PauseChannelAction $action): JsonResponse
    {
        $result = $action->execute($channel);

        return $this->success(new ChannelResource($result->data()), $result->message());
    }

    public function resume(string $channel, ResumeChannelAction $action): JsonResponse
    {
        $result = $action->execute($channel);

        return $this->success(new ChannelResource($result->data()), $result->message());
    }

    public function acknowledgeShippingMapping(string $channel, AcknowledgeShippingMappingAction $action): JsonResponse
    {
        $result = $action->execute($channel);

        return $this->success(new ChannelResource($result->data()), $result->message());
    }

    public function disable(string $channel, DisableChannelAction $action): JsonResponse
    {
        $result = $action->execute($channel);

        return $this->success(new ChannelResource($result->data()), $result->message());
    }

    public function reenable(string $channel, ReenableChannelAction $action): JsonResponse
    {
        $result = $action->execute($channel);

        return $this->success(new ChannelResource($result->data()), $result->message());
    }
}
