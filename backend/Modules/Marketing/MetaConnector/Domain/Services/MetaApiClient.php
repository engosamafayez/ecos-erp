<?php

declare(strict_types=1);

namespace Modules\Marketing\MetaConnector\Domain\Services;

use Illuminate\Support\Facades\Http;
use Modules\Marketing\Connections\Domain\Contracts\MarketingApiClientContract;
use RuntimeException;

/**
 * Low-level Meta Graph API HTTP client.
 *
 * Implements MarketingApiClientContract so the core OAuth orchestrator
 * can treat all platform clients uniformly.
 *
 * Stateless: every call receives the access_token explicitly.
 */
final class MetaApiClient implements MarketingApiClientContract
{
    private const GRAPH_BASE = 'https://graph.facebook.com/v21.0';
    private const OAUTH_BASE = 'https://www.facebook.com/v21.0/dialog/oauth';
    private const TOKEN_URL  = 'https://graph.facebook.com/v21.0/oauth/access_token';

    public function __construct(
        private readonly string $appId,
        private readonly string $appSecret,
    ) {}

    // ── OAuth ─────────────────────────────────────────────────────────────────

    /**
     * Generate an OAuth authorization URL.
     *
     * @param list<string> $scopes
     */
    public function buildAuthUrl(string $redirectUri, string $state, array $scopes): string
    {
        return self::OAUTH_BASE . '?' . http_build_query([
            'client_id'     => $this->appId,
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
            'scope'         => implode(',', $scopes),
            'response_type' => 'code',
        ]);
    }

    /**
     * Exchange an auth code for tokens.
     *
     * @return array{access_token: string, token_type: string, expires_in: int|null}
     */
    public function exchangeCode(string $code, string $redirectUri): array
    {
        $response = Http::get(self::TOKEN_URL, [
            'client_id'     => $this->appId,
            'client_secret' => $this->appSecret,
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
        ]);

        $this->assertSuccess($response, 'token exchange');

        return $response->json();
    }

    /**
     * Extend a short-lived token to a long-lived token (60-day).
     *
     * @return array{access_token: string, token_type: string, expires_in: int}
     */
    public function extendToken(string $shortLivedToken): array
    {
        $response = Http::get(self::TOKEN_URL, [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->appId,
            'client_secret'     => $this->appSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        $this->assertSuccess($response, 'token extension');

        return $response->json();
    }

    /**
     * Inspect an access token to get permissions and validity.
     */
    public function debugToken(string $userToken): array
    {
        $appToken  = $this->appId . '|' . $this->appSecret;
        $response  = Http::get(self::GRAPH_BASE . '/debug_token', [
            'input_token'  => $userToken,
            'access_token' => $appToken,
        ]);

        $this->assertSuccess($response, 'debug_token');

        return $response->json('data', []);
    }

    // ── Business Manager ──────────────────────────────────────────────────────

    /** @return array{data: list<array{id: string, name: string}>} */
    public function getBusinessManagers(string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . '/me/businesses', [
            'access_token' => $accessToken,
            'fields'       => 'id,name,link,primary_page,timezone_id,created_time',
        ]);

        $this->assertSuccess($response, 'businesses');

        return $response->json();
    }

    public function getAdAccountsForBusiness(string $businessId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$businessId}/owned_ad_accounts", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,account_id,account_status,currency,timezone_name,business,created_time',
            'limit'        => 100,
        ]);

        $this->assertSuccess($response, 'owned_ad_accounts');

        return $response->json('data', []);
    }

    public function getPagesForBusiness(string $businessId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$businessId}/owned_pages", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,category,fan_count,verification_status,link,created_time',
            'limit'        => 100,
        ]);

        $this->assertSuccess($response, 'owned_pages');

        return $response->json('data', []);
    }

    public function getInstagramAccountsForBusiness(string $businessId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$businessId}/instagram_accounts", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,username,profile_pic,followers_count,is_verified',
            'limit'        => 100,
        ]);

        $this->assertSuccess($response, 'instagram_accounts');

        return $response->json('data', []);
    }

    public function getWhatsAppAccountsForBusiness(string $businessId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$businessId}/whatsapp_business_accounts", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,currency,timezone_id,message_template_namespace',
            'limit'        => 100,
        ]);

        $this->assertSuccess($response, 'whatsapp_business_accounts');

        return $response->json('data', []);
    }

    public function getPixelsForBusiness(string $businessId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$businessId}/owned_pixels", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,code,creation_time,last_fired_time,is_unavailable',
            'limit'        => 100,
        ]);

        $this->assertSuccess($response, 'owned_pixels');

        return $response->json('data', []);
    }

    public function getCatalogsForBusiness(string $businessId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$businessId}/owned_product_catalogs", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,product_count,vertical,created_time',
            'limit'        => 100,
        ]);

        $this->assertSuccess($response, 'owned_product_catalogs');

        return $response->json('data', []);
    }

    public function getDomainsForBusiness(string $businessId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$businessId}/owned_domains", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,created_time',
            'limit'        => 100,
        ]);

        $this->assertSuccess($response, 'owned_domains');

        return $response->json('data', []);
    }

    public function getDatasetsForBusiness(string $businessId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$businessId}/datasets", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,event_stats,created_time',
            'limit'        => 100,
        ]);

        $this->assertSuccess($response, 'datasets');

        return $response->json('data', []);
    }

    public function getAppsForBusiness(string $businessId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$businessId}/owned_apps", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,category,created_time',
            'limit'        => 100,
        ]);

        $this->assertSuccess($response, 'owned_apps');

        return $response->json('data', []);
    }

    // ── Campaign Discovery ────────────────────────────────────────────────────

    /**
     * Get all campaigns for an ad account.
     *
     * @param  array<string, mixed> $extraParams
     * @return array{data: list<array<string, mixed>>}
     */
    public function getCampaignsForAdAccount(string $adAccountId, string $accessToken, array $extraParams = []): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$adAccountId}/campaigns", array_merge([
            'access_token' => $accessToken,
            'fields'       => 'id,name,status,objective,buying_type,bid_strategy,daily_budget,lifetime_budget,budget_remaining,start_time,stop_time,created_time,updated_time,account_id',
            'limit'        => 100,
        ], $extraParams));

        $this->assertSuccess($response, 'campaigns');

        return $response->json();
    }

    /** @return list<array<string, mixed>> */
    public function getAdSetsForCampaign(string $campaignId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$campaignId}/adsets", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,status,daily_budget,lifetime_budget,bid_amount,bid_strategy,optimization_goal,billing_event,targeting,start_time,end_time,campaign_id,created_time,updated_time',
            'limit'        => 200,
        ]);

        $this->assertSuccess($response, 'adsets');

        return $response->json('data', []);
    }

    /** @return list<array<string, mixed>> */
    public function getAdsForAdSet(string $adSetId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$adSetId}/ads", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,status,creative,tracking_specs,adset_id,campaign_id,created_time,updated_time',
            'limit'        => 200,
        ]);

        $this->assertSuccess($response, 'ads');

        return $response->json('data', []);
    }

    /**
     * Fetch insights for any level (campaign / adset / ad).
     *
     * @return list<array<string, mixed>>
     */
    public function getInsights(
        string  $entityId,
        string  $accessToken,
        string  $datePreset  = 'last_30d',
        ?string $dateStart   = null,
        ?string $dateStop    = null,
        string  $level       = 'campaign',
    ): array {
        $params = [
            'access_token' => $accessToken,
            'fields'       => 'spend,reach,impressions,frequency,cpm,cpc,ctr,clicks,outbound_clicks,landing_page_views,video_p100_watched_actions,actions,date_start,date_stop',
            'time_increment' => 1,    // Daily breakdown
            'limit'        => 100,
        ];

        if ($dateStart !== null && $dateStop !== null) {
            $params['time_range'] = json_encode(['since' => $dateStart, 'until' => $dateStop]);
        } else {
            $params['date_preset'] = $datePreset;
        }

        $response = Http::get(self::GRAPH_BASE . "/{$entityId}/insights", $params);

        $this->assertSuccess($response, "insights/{$level}");

        return $response->json('data', []);
    }

    /** @return list<array<string, mixed>> */
    public function getAdCreatives(string $adId, string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . "/{$adId}/adcreatives", [
            'access_token' => $accessToken,
            'fields'       => 'id,name,title,body,call_to_action_type,object_story_spec,image_url,video_id,thumbnail_url,link_url,asset_feed_spec',
            'limit'        => 20,
        ]);

        $this->assertSuccess($response, 'adcreatives');

        return $response->json('data', []);
    }

    // ── Health check ──────────────────────────────────────────────────────────

    public function getMe(string $accessToken): array
    {
        $response = Http::get(self::GRAPH_BASE . '/me', [
            'access_token' => $accessToken,
            'fields'       => 'id,name',
        ]);

        $this->assertSuccess($response, 'me');

        return $response->json();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function assertSuccess(\Illuminate\Http\Client\Response $response, string $context): void
    {
        if (! $response->successful()) {
            $error   = $response->json('error.message', 'Unknown error');
            $code    = $response->json('error.code', 0);
            throw new RuntimeException(
                "Meta API [{$context}] failed (code {$code}): {$error}"
            );
        }
    }
}
