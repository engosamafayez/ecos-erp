<?php

declare(strict_types=1);

namespace Modules\Marketing\Campaigns\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CampaignInsightResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                          => $this->id,
            'marketing_campaign_id'       => $this->marketing_campaign_id,
            'marketing_campaign_ad_set_id' => $this->marketing_campaign_ad_set_id,
            'marketing_campaign_ad_id'    => $this->marketing_campaign_ad_id,
            'connector_type'              => $this->connector_type,
            'level'                       => $this->level->value,
            'date_start'                  => $this->date_start?->toDateString(),
            'date_stop'                   => $this->date_stop?->toDateString(),
            'spend'                       => $this->spend,
            'reach'                       => $this->reach,
            'impressions'                 => $this->impressions,
            'frequency'                   => $this->frequency,
            'cpm'                         => $this->cpm,
            'cpc'                         => $this->cpc,
            'ctr'                         => $this->ctr,
            'clicks'                      => $this->clicks,
            'outbound_clicks'             => $this->outbound_clicks,
            'landing_page_views'          => $this->landing_page_views,
            'video_views'                 => $this->video_views,
            'messages'                    => $this->messages,
            'leads'                       => $this->leads,
            'purchases'                   => $this->purchases,
            'add_to_cart'                 => $this->add_to_cart,
            'initiate_checkout'           => $this->initiate_checkout,
            'conversions'                 => $this->conversions,
            'cost_per_result'             => $this->cost_per_result,
            'synced_at'                   => $this->synced_at?->toIso8601String(),
            'created_at'                  => $this->created_at?->toIso8601String(),
        ];
    }
}
