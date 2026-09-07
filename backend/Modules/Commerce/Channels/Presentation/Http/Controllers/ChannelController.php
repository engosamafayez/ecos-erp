<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Channels\Application\Actions\CreateChannelAction;
use Modules\Commerce\Channels\Application\Actions\DeleteChannelAction;
use Modules\Commerce\Channels\Application\Actions\GetChannelAction;
use Modules\Commerce\Channels\Application\Actions\ListChannelsAction;
use Modules\Commerce\Channels\Application\Actions\UpdateChannelAction;
use Modules\Commerce\Channels\Application\DTO\ChannelDTO;
use Modules\Commerce\Channels\Presentation\Http\Requests\StoreChannelRequest;
use Modules\Commerce\Channels\Presentation\Http\Requests\UpdateChannelRequest;
use Modules\Commerce\Channels\Presentation\Http\Resources\ChannelResource;

final class ChannelController extends Controller
{
    use HasApiResponse;

    /**
     * CD-03 (TASK-ECOS-COMMERCE-PRE-USER-REVIEW-REMEDIATION-002 §4).
     *
     * `company_id` below is a NARROWING FILTER ONLY and carries no authority. The tenant
     * boundary is enforced by the `tenant` global scope on the Channel model
     * (TenantOwnershipResolver), which every query in this controller passes through — so
     * omitting the parameter no longer widens the result to all companies, and supplying
     * another company's id can only intersect to an empty set for an unprivileged actor.
     * For an is_system actor the scope stands down (existing IAM authority) and the
     * parameter keeps working as the cross-company selector it already was.
     *
     * It is deliberately NOT replaced by `CurrentCompanyService::id()` here: that would
     * state the boundary a second time in the read path, and two sources answering "which
     * company owns this row?" is precisely the RC-6 defect class TenantOwnershipResolver
     * exists to prevent.
     */
    public function index(Request $request, ListChannelsAction $action): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status', 'all'),
            'platform' => $request->query('platform'),
            'company_id' => $request->query('company_id'),
            'brand_id' => $request->query('brand_id'),
            'business_account_id' => $request->query('business_account_id'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 10),
        ];

        $paginator = $action->execute($filters)->data();

        return $this->success([
            'items' => ChannelResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $channel, GetChannelAction $action): JsonResponse
    {
        $model = $action->execute($channel)->data();

        return $this->success(new ChannelResource($model));
    }

    public function store(StoreChannelRequest $request, CreateChannelAction $action): JsonResponse
    {
        $result = $action->execute(ChannelDTO::fromArray($request->validated()));

        return $this->created(new ChannelResource($result->data()), $result->message());
    }

    public function update(
        UpdateChannelRequest $request,
        string $channel,
        UpdateChannelAction $action,
    ): JsonResponse {
        $result = $action->execute($channel, ChannelDTO::fromArray($request->validated()));

        return $this->updated(new ChannelResource($result->data()), $result->message());
    }

    public function destroy(string $channel, DeleteChannelAction $action): JsonResponse
    {
        $result = $action->execute($channel);

        return $this->deleted($result->message() ?? 'Channel deleted successfully.');
    }
}
