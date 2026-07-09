<?php

declare(strict_types=1);

namespace Modules\Marketing\Campaigns\Application\Services;

use Modules\Marketing\Assets\Domain\Enums\AssetType;
use Modules\Marketing\Assets\Domain\Models\MarketingAsset;
use Modules\Marketing\Campaigns\Domain\Contracts\CampaignConnectorContract;
use Modules\Marketing\Campaigns\Domain\Models\Campaign;
use Modules\Marketing\Campaigns\Domain\Models\CampaignAd;
use Modules\Marketing\Campaigns\Domain\Models\CampaignAdSet;
use Modules\Marketing\Campaigns\Domain\Models\CampaignCreative;
use Modules\Marketing\Connections\Application\Services\ConnectorRegistry;
use Modules\Marketing\Connections\Domain\Models\MarketingConnection;
use Throwable;

/**
 * Orchestrates full campaign discovery for a Marketing Connection.
 *
 * Flow:
 *  1. Find all Ad Account assets linked to the connection
 *  2. For each ad account, discover campaigns via CampaignConnectorContract
 *  3. For each campaign, discover ad sets
 *  4. For each ad set, discover ads
 *  5. For each ad, discover creatives
 *
 * Upserts records by provider external ID to avoid duplicates.
 * Historical insights are synced separately by CampaignInsightSyncService.
 */
final class CampaignSyncService
{
    public function __construct(
        private readonly ConnectorRegistry $registry,
    ) {}

    /**
     * @return array{campaigns: int, ad_sets: int, ads: int, creatives: int, errors: int}
     */
    public function syncForConnection(
        MarketingConnection $connection,
        bool               $includeCreatives = true,
    ): array {
        $connectorType = $connection->connector_type->value;

        if (! $this->registry->has($connectorType)) {
            return ['campaigns' => 0, 'ad_sets' => 0, 'ads' => 0, 'creatives' => 0, 'errors' => 0];
        }

        $connector = $this->registry->get($connectorType);

        if (! $connector instanceof CampaignConnectorContract) {
            return ['campaigns' => 0, 'ad_sets' => 0, 'ads' => 0, 'creatives' => 0, 'errors' => 0];
        }

        // Get all Ad Account assets for this connection
        $adAccounts = MarketingAsset::where('marketing_connection_id', $connection->id)
            ->whereIn('asset_type', [AssetType::AdAccount->value])
            ->get();

        $counts = ['campaigns' => 0, 'ad_sets' => 0, 'ads' => 0, 'creatives' => 0, 'errors' => 0];

        foreach ($adAccounts as $adAccount) {
            try {
                $this->syncAdAccount($connector, $connection, $adAccount->external_id, $includeCreatives, $counts);
            } catch (Throwable) {
                $counts['errors']++;
            }
        }

        return $counts;
    }

    /**
     * Sync a single ad account's campaigns.
     *
     * @param  array{campaigns: int, ad_sets: int, ads: int, creatives: int, errors: int} $counts
     */
    private function syncAdAccount(
        CampaignConnectorContract $connector,
        MarketingConnection       $connection,
        string                    $adAccountId,
        bool                      $includeCreatives,
        array                     &$counts,
    ): void {
        $campaignDescriptors = $connector->discoverCampaigns($adAccountId, $connection);

        foreach ($campaignDescriptors as $descriptor) {
            try {
                $campaign = $this->upsertCampaign($connection, $adAccountId, $descriptor);
                $counts['campaigns']++;

                // Ad Sets
                $adSetDescriptors = $connector->discoverAdSets($descriptor['external_campaign_id'], $connection);
                foreach ($adSetDescriptors as $asDescriptor) {
                    try {
                        $adSet = $this->upsertAdSet($connection, $campaign, $asDescriptor);
                        $counts['ad_sets']++;

                        // Ads
                        $adDescriptors = $connector->discoverAds($asDescriptor['external_ad_set_id'], $connection);
                        foreach ($adDescriptors as $adDescriptor) {
                            try {
                                $ad = $this->upsertAd($connection, $campaign, $adSet, $adDescriptor);
                                $counts['ads']++;

                                // Creatives (optional)
                                if ($includeCreatives && $adDescriptor['creative_id']) {
                                    try {
                                        $creatives = $connector->discoverCreatives($adDescriptor['external_ad_id'], $connection);
                                        foreach ($creatives as $cDescriptor) {
                                            $this->upsertCreative($connection, $campaign, $ad, $cDescriptor);
                                            $counts['creatives']++;
                                        }
                                    } catch (Throwable) {
                                        $counts['errors']++;
                                    }
                                }
                            } catch (Throwable) {
                                $counts['errors']++;
                            }
                        }
                    } catch (Throwable) {
                        $counts['errors']++;
                    }
                }
            } catch (Throwable) {
                $counts['errors']++;
            }
        }
    }

    /** @param array<string, mixed> $d */
    private function upsertCampaign(MarketingConnection $connection, string $adAccountId, array $d): Campaign
    {
        return Campaign::updateOrCreate(
            [
                'connector_type'       => $connection->connector_type->value,
                'external_campaign_id' => $d['external_campaign_id'],
            ],
            [
                'marketing_connection_id' => $connection->id,
                'company_id'              => $connection->company_id,
                'external_account_id'     => $adAccountId,
                'name'                    => $d['name'],
                'status'                  => $d['status'],
                'objective'               => $d['objective'],
                'buying_type'             => $d['buying_type'],
                'bid_strategy'            => $d['bid_strategy'],
                'daily_budget'            => $d['daily_budget'],
                'lifetime_budget'         => $d['lifetime_budget'],
                'budget_remaining'        => $d['budget_remaining'],
                'start_time'              => $d['start_time'],
                'stop_time'               => $d['stop_time'],
                'provider_created_at'     => $d['provider_created_at'],
                'provider_updated_at'     => $d['provider_updated_at'],
                'last_synced_at'          => now(),
                'next_sync_at'            => now()->addHours(6),
                'provider_payload'        => $d['provider_payload'],
            ],
        );
    }

    /** @param array<string, mixed> $d */
    private function upsertAdSet(MarketingConnection $connection, Campaign $campaign, array $d): CampaignAdSet
    {
        return CampaignAdSet::updateOrCreate(
            ['external_ad_set_id' => $d['external_ad_set_id']],
            [
                'marketing_campaign_id'  => $campaign->id,
                'marketing_connection_id' => $connection->id,
                'external_campaign_id'   => $campaign->external_campaign_id,
                'name'                   => $d['name'],
                'status'                 => $d['status'],
                'daily_budget'           => $d['daily_budget'],
                'lifetime_budget'        => $d['lifetime_budget'],
                'bid_amount'             => $d['bid_amount'],
                'bid_strategy'           => $d['bid_strategy'],
                'optimization_goal'      => $d['optimization_goal'],
                'billing_event'          => $d['billing_event'],
                'targeting'              => $d['targeting'],
                'start_time'             => $d['start_time'],
                'end_time'               => $d['end_time'],
                'last_synced_at'         => now(),
                'provider_payload'       => $d['provider_payload'],
            ],
        );
    }

    /** @param array<string, mixed> $d */
    private function upsertAd(MarketingConnection $connection, Campaign $campaign, CampaignAdSet $adSet, array $d): CampaignAd
    {
        return CampaignAd::updateOrCreate(
            ['external_ad_id' => $d['external_ad_id']],
            [
                'marketing_campaign_id'         => $campaign->id,
                'marketing_campaign_ad_set_id'  => $adSet->id,
                'marketing_connection_id'        => $connection->id,
                'external_ad_set_id'             => $d['external_ad_set_id'],
                'external_campaign_id'           => $d['external_campaign_id'],
                'name'                           => $d['name'],
                'status'                         => $d['status'],
                'creative_id'                    => $d['creative_id'],
                'tracking_specs'                 => $d['tracking_specs'],
                'last_synced_at'                 => now(),
                'provider_payload'               => $d['provider_payload'],
            ],
        );
    }

    /** @param array<string, mixed> $d */
    private function upsertCreative(MarketingConnection $connection, Campaign $campaign, CampaignAd $ad, array $d): CampaignCreative
    {
        return CampaignCreative::updateOrCreate(
            ['external_creative_id' => $d['external_creative_id']],
            [
                'marketing_connection_id' => $connection->id,
                'marketing_campaign_id'  => $campaign->id,
                'marketing_campaign_ad_id' => $ad->id,
                'name'             => $d['name'],
                'headline'         => $d['headline'],
                'primary_text'     => $d['primary_text'],
                'call_to_action'   => $d['call_to_action'],
                'image_url'        => $d['image_url'],
                'video_url'        => $d['video_url'],
                'thumbnail_url'    => $d['thumbnail_url'],
                'link_url'         => $d['link_url'],
                'asset_feed'       => $d['asset_feed'],
                'provider_payload' => $d['provider_payload'],
                'last_synced_at'   => now(),
            ],
        );
    }
}
