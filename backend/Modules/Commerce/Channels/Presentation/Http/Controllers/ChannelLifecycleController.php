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

        // TASK-...-WOO-05 carry-forward correction: a GET must be side-effect free — it never
        // persists a lifecycle transition merely because someone asked for readiness. This
        // computes and returns the CURRENT derived pre-live state without writing it; the
        // persisted `channel.lifecycle_state` (in the ChannelResource below) may legitimately
        // differ from `derived_pre_live_state` until an explicit action (TransitionChannelToLiveAction,
        // ReenableChannelAction) actually refreshes and persists it.
        $derived = $readiness->isPreLiveState($model->lifecycle_state)
            ? $readiness->derivePreLiveState($model)
            : $model->lifecycle_state;

        return $this->success([
            'channel' => new ChannelResource($model),
            'derived_pre_live_state' => $derived->value,
            'derived_pre_live_state_label' => $derived->label(),
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
