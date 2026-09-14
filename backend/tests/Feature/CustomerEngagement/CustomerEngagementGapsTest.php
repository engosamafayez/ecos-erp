<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEngagement;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\CustomerEngagement\Application\Services\BusinessHoursService;
use Modules\CustomerEngagement\Application\Services\ChannelProviderService;
use Modules\CustomerEngagement\Application\Services\EngagementTimelineService;
use Modules\CustomerEngagement\Application\Services\WebhookIngestService;
use Modules\CustomerEngagement\Domain\Enums\ChannelProviderStatus;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Domain\Models\Conversation;
use Modules\CustomerEngagement\Domain\Models\Message;
use Modules\CustomerEngagement\Domain\Models\SlaPolicy;
use Modules\CustomerEngagement\Presentation\Http\Resources\ChannelProviderResource;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §30 OMNICHANNEL items 1-5.
 * Covers the four confirmed CustomerEngagement gaps closed in §3, plus the cross-channel
 * timeline read model.
 */
final class CustomerEngagementGapsTest extends TestCase
{
    use DatabaseTransactions;

    // ── 1: inbound conversation receives Brand from canonical ChannelProvider ──────

    public function test_inbound_conversation_receives_brand_from_channel_provider(): void
    {
        $company = Company::factory()->create();
        $brand = Brand::create(['company_id' => $company->id, 'code' => 'MAIN', 'name' => 'Main Brand']);
        $config = ChannelProvider::create([
            'company_id' => $company->id,
            'brand_id' => $brand->id,
            'channel' => 'whatsapp',
            'display_name' => 'WA Main',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => ['token' => 'secret'],
        ]);

        app(WebhookIngestService::class)->processBatch($config, [[
            'conversation_id' => 'ext-thread-1',
            'message_id' => 'msg-1',
            'sender_id' => 'wa-user-1',
            'sender_name' => 'Test Customer',
            'sender_phone' => '201012345678',
            'message_type' => 'text',
            'content' => 'hello',
            'media_url' => null,
            'media_type' => null,
            'timestamp' => now()->timestamp,
        ]]);

        $conversation = Conversation::query()->where('external_conversation_id', 'ext-thread-1')->firstOrFail();

        $this->assertSame($brand->id, $conversation->brand_id);
        $this->assertSame($config->id, $conversation->channel_id);
        $this->assertSame($company->id, $conversation->company_id);
    }

    // ── 2: cross-company/Brand inbound event rejected ───────────────────────────────

    public function test_inbound_conversation_never_uses_a_different_companys_brand(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $brandB = Brand::create(['company_id' => $companyB->id, 'code' => 'B', 'name' => 'Brand B']);

        $configA = ChannelProvider::create([
            'company_id' => $companyA->id,
            'brand_id' => null,
            'channel' => 'whatsapp',
            'display_name' => 'WA A',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => [],
        ]);

        app(WebhookIngestService::class)->processBatch($configA, [[
            'conversation_id' => 'ext-thread-2',
            'message_id' => 'msg-2',
            'sender_id' => 'wa-user-2',
            'sender_name' => 'Another Customer',
            'sender_phone' => '201099999999',
            'message_type' => 'text',
            'content' => 'hi',
            'media_url' => null,
            'media_type' => null,
            'timestamp' => now()->timestamp,
        ]]);

        $conversation = Conversation::query()->where('external_conversation_id', 'ext-thread-2')->firstOrFail();

        $this->assertSame($companyA->id, $conversation->company_id);
        $this->assertNotSame($brandB->id, $conversation->brand_id);
        $this->assertNull($conversation->brand_id);
    }

    // ── 3: business-hours rule enforced ──────────────────────────────────────────────

    public function test_sla_due_date_respects_business_hours_when_policy_requires_it(): void
    {
        $company = Company::factory()->create();
        $policy = SlaPolicy::create([
            'company_id' => $company->id,
            'name' => '9-to-6 Support',
            'first_response_minutes' => 60,
            'resolution_minutes' => 60,
            'business_hours_only' => true,
            'business_hours' => ['mon' => ['09:00', '18:00'], 'tue' => ['09:00', '18:00'], 'wed' => ['09:00', '18:00'], 'thu' => ['09:00', '18:00'], 'fri' => ['09:00', '18:00']],
            'timezone' => 'UTC',
            'is_default' => true,
        ]);

        // Monday 17:30 UTC + 60 "business" minutes should land Tuesday 09:30, not Monday 18:30.
        $startedAt = \Carbon\Carbon::parse('2026-09-14 17:30:00', 'UTC'); // a Monday
        $due = app(BusinessHoursService::class)->addBusinessMinutes($policy, $startedAt, 60);

        $this->assertSame('2026-09-15 09:30:00', $due->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    public function test_business_hours_only_false_uses_plain_calendar_time(): void
    {
        $company = Company::factory()->create();
        $policy = SlaPolicy::create([
            'company_id' => $company->id,
            'name' => '24/7',
            'first_response_minutes' => 60,
            'resolution_minutes' => 60,
            'business_hours_only' => false,
            'is_default' => true,
        ]);

        $startedAt = \Carbon\Carbon::parse('2026-09-14 17:30:00', 'UTC');
        $due = app(BusinessHoursService::class)->addBusinessMinutes($policy, $startedAt, 60);

        $this->assertSame('2026-09-14 18:30:00', $due->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'));
    }

    // ── 4: encrypted provider credentials not exposed ────────────────────────────────

    public function test_channel_provider_credentials_are_encrypted_at_rest_and_never_serialized(): void
    {
        $company = Company::factory()->create();
        $config = app(ChannelProviderService::class)->create([
            'company_id' => $company->id,
            'channel' => 'whatsapp',
            'display_name' => 'WA',
            'credentials' => ['access_token' => 'super-secret-token'],
        ]);

        $raw = \Illuminate\Support\Facades\DB::table('cep_channel_providers')->where('id', $config->id)->value('credentials');
        $this->assertStringNotContainsString('super-secret-token', (string) $raw, 'credentials were stored in plaintext');

        $fresh = ChannelProvider::query()->find($config->id);
        $this->assertSame('super-secret-token', $fresh->getCredential('access_token'), 'the transitional cast must still decrypt correctly on read');

        $resourceArray = (new ChannelProviderResource($fresh))->toArray(request());
        $this->assertArrayNotHasKey('credentials', $resourceArray);
        $this->assertArrayNotHasKey('webhook_secret', $resourceArray);
    }

    public function test_legacy_plaintext_credentials_still_read_correctly(): void
    {
        $company = Company::factory()->create();
        $id = (string) Str::uuid();

        // Simulate a pre-existing plaintext row written before this task (bypasses the model's
        // own cast via a raw insert, exactly as a real legacy row would already exist).
        \Illuminate\Support\Facades\DB::table('cep_channel_providers')->insert([
            'id' => $id,
            'company_id' => $company->id,
            'channel' => 'whatsapp',
            'display_name' => 'Legacy WA',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => json_encode(['access_token' => 'legacy-plaintext-token']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacy = ChannelProvider::query()->find($id);
        $this->assertSame('legacy-plaintext-token', $legacy->getCredential('access_token'));
    }

    // ── 5: cross-channel customer timeline preserves channel identity ───────────────

    public function test_engagement_timeline_merges_messages_and_calls_across_channels(): void
    {
        $company = Company::factory()->create();
        $customerId = (string) Str::uuid();

        $waConversation = Conversation::create([
            'company_id' => $company->id,
            'provider' => 'whatsapp',
            'external_conversation_id' => 'wa-thread',
            'conversation_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'status' => 'open',
            'started_at' => now()->subHour(),
        ]);
        Message::create([
            'conversation_id' => $waConversation->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'hi from whatsapp',
            'sender_type' => 'customer',
            'sent_at' => now()->subHour(),
        ]);

        $voiceConversation = Conversation::create([
            'company_id' => $company->id,
            'provider' => 'voice',
            'external_conversation_id' => 'call-1',
            'conversation_uuid' => (string) Str::uuid(),
            'customer_id' => $customerId,
            'status' => 'open',
            'started_at' => now(),
        ]);
        $config = ChannelProvider::create([
            'company_id' => $company->id,
            'channel' => 'voice',
            'display_name' => 'Voice',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => [],
        ]);
        Call::create([
            'conversation_id' => $voiceConversation->id,
            'company_id' => $company->id,
            'channel_provider_id' => $config->id,
            'customer_id' => $customerId,
            'direction' => 'inbound',
            'from_number' => '201000000000',
            'to_number' => '201099999999',
            'provider_call_id' => 'prov-call-1',
            'provider' => 'voice',
            'canonical_state' => 'completed',
            'started_at' => now(),
            'duration_seconds' => 120,
        ]);

        $items = app(EngagementTimelineService::class)->forCustomer($company->id, $customerId);

        $types = array_map(fn ($i) => $i->type, $items);
        $providers = array_map(fn ($i) => $i->provider, $items);

        $this->assertContains('message', $types);
        $this->assertContains('call', $types);
        $this->assertContains('whatsapp', $providers);
        $this->assertContains('voice', $providers);

        // A call must never be represented as type=message (§3D: no flattening).
        foreach ($items as $item) {
            if ($item->provider === 'voice') {
                $this->assertSame('call', $item->type);
            }
        }
    }
}
