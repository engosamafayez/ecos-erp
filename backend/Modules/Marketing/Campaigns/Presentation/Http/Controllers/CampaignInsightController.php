<?php

declare(strict_types=1);

namespace Modules\Marketing\Campaigns\Presentation\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Marketing\Campaigns\Domain\Models\Campaign;
use Modules\Marketing\Campaigns\Domain\Models\CampaignInsight;
use Modules\Marketing\Campaigns\Presentation\Http\Resources\CampaignInsightResource;

final class CampaignInsightController extends Controller
{
    /**
     * Latest insights per level (one row per campaign/adset/ad per day).
     */
    public function index(Request $request, Campaign $campaign): AnonymousResourceCollection
    {
        $query = $campaign->insights()
            ->when($request->query('level'), fn ($q, $level) => $q->where('level', $level))
            ->when($request->query('date_start'), fn ($q, $v) => $q->whereDate('date_start', '>=', $v))
            ->when($request->query('date_stop'), fn ($q, $v) => $q->whereDate('date_stop', '<=', $v));

        $perPage = min((int) $request->query('per_page', 30), 200);

        $insights = $query
            ->orderBy('date_start', 'desc')
            ->orderBy('synced_at', 'desc')
            ->paginate($perPage);

        return CampaignInsightResource::collection($insights);
    }

    /**
     * Aggregated daily trend for a campaign (campaign-level only).
     * Returns one row per date, using the latest synced snapshot for each date.
     */
    public function trend(Request $request, Campaign $campaign): AnonymousResourceCollection
    {
        $days    = min((int) $request->query('days', 30), 365);
        $dateFrom = now()->subDays($days)->toDateString();

        $insights = CampaignInsight::query()
            ->where('marketing_campaign_id', $campaign->id)
            ->where('level', 'campaign')
            ->whereDate('date_start', '>=', $dateFrom)
            ->orderBy('date_start')
            ->orderByDesc('synced_at')
            ->get()
            ->unique('date_start');  // keep latest synced per date

        return CampaignInsightResource::collection($insights);
    }
}
