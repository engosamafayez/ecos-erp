<?php

declare(strict_types=1);

namespace Modules\Marketing\Campaigns\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Modules\Marketing\Campaigns\Domain\Models\Campaign;
use Modules\Marketing\Campaigns\Domain\Models\CampaignInsight;

/**
 * Executive Campaign Dashboard (Phase 9).
 *
 * Provides KPI totals + trend data for the marketing leadership view.
 * Always reads from insight snapshots — never from live provider data.
 */
final class CampaignDashboardController extends Controller
{
    /** GET /marketing/campaigns/dashboard */
    public function index(Request $request): JsonResponse
    {
        $days     = min((int) $request->query('days', 30), 365);
        $dateFrom = now()->subDays($days)->toDateString();
        $dateTo   = now()->toDateString();

        $companyId = $request->query('company_id');

        // KPI totals
        $kpis = DB::table('marketing_campaign_insights')
            ->where('level', 'campaign')
            ->whereDate('date_start', '>=', $dateFrom)
            ->whereDate('date_stop', '<=', $dateTo)
            ->when($companyId, function ($q) use ($companyId) {
                $q->whereIn('marketing_campaign_id', function ($sub) use ($companyId) {
                    $sub->select('id')->from('marketing_campaigns')->where('company_id', $companyId);
                });
            })
            ->selectRaw('
                SUM(spend)             as total_spend,
                SUM(impressions)       as total_impressions,
                SUM(clicks)            as total_clicks,
                SUM(reach)             as total_reach,
                SUM(purchases)         as total_purchases,
                SUM(leads)             as total_leads,
                SUM(messages)          as total_messages,
                AVG(ctr)               as avg_ctr,
                AVG(cpc)               as avg_cpc,
                AVG(cpm)               as avg_cpm,
                COUNT(DISTINCT marketing_campaign_id) as campaign_count
            ')
            ->first();

        // Status distribution
        $statusDistribution = Campaign::query()
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->get()
            ->pluck('count', 'status');

        // Daily spend trend (last N days, campaign level)
        $dailyTrend = DB::table('marketing_campaign_insights')
            ->where('level', 'campaign')
            ->whereDate('date_start', '>=', $dateFrom)
            ->when($companyId, function ($q) use ($companyId) {
                $q->whereIn('marketing_campaign_id', function ($sub) use ($companyId) {
                    $sub->select('id')->from('marketing_campaigns')->where('company_id', $companyId);
                });
            })
            ->selectRaw('date_start, SUM(spend) as spend, SUM(impressions) as impressions, AVG(ctr) as ctr, AVG(cpc) as cpc')
            ->groupBy('date_start')
            ->orderBy('date_start')
            ->get();

        // Active / Paused / Total campaign counts
        $totalCampaigns  = Campaign::when($companyId, fn ($q) => $q->where('company_id', $companyId))->count();
        $activeCampaigns = Campaign::when($companyId, fn ($q) => $q->where('company_id', $companyId))->where('status', 'ACTIVE')->count();

        return response()->json([
            'kpis' => [
                'total_spend'      => $kpis?->total_spend,
                'total_impressions' => $kpis?->total_impressions,
                'total_clicks'     => $kpis?->total_clicks,
                'total_reach'      => $kpis?->total_reach,
                'total_purchases'  => $kpis?->total_purchases,
                'total_leads'      => $kpis?->total_leads,
                'total_messages'   => $kpis?->total_messages,
                'avg_ctr'          => $kpis?->avg_ctr,
                'avg_cpc'          => $kpis?->avg_cpc,
                'avg_cpm'          => $kpis?->avg_cpm,
                'campaign_count'   => $kpis?->campaign_count ?? 0,
            ],
            'campaigns' => [
                'total'  => $totalCampaigns,
                'active' => $activeCampaigns,
            ],
            'status_distribution' => $statusDistribution,
            'daily_trend'         => $dailyTrend,
            'period' => [
                'days'      => $days,
                'date_from' => $dateFrom,
                'date_to'   => $dateTo,
            ],
        ]);
    }
}
