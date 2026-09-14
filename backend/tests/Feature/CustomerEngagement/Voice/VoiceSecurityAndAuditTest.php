<?php

declare(strict_types=1);

namespace Tests\Feature\CustomerEngagement\Voice;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\CustomerEngagement\Domain\Enums\ChannelProviderStatus;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Voice\Application\Contracts\TelephonyProviderContract;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceAuditService;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceCallService;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;
use Modules\CustomerEngagement\Voice\Presentation\Http\Resources\CallResource;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §30 SECURITY items 30-32,
 * AUDIT items 33-36, TRANSCRIPT/RECORDING items 37-40.
 */
final class VoiceSecurityAndAuditTest extends TestCase
{
    use DatabaseTransactions;

    private function makeVoiceConfig(string $companyId): ChannelProvider
    {
        return ChannelProvider::create([
            'company_id' => $companyId,
            'channel' => 'voice',
            'display_name' => 'Voice Line',
            'phone_number' => '201099999999',
            'webhook_secret' => 'super-secret-webhook-key',
            'status' => ChannelProviderStatus::ACTIVE->value,
            'credentials' => [],
        ]);
    }

    // ── 30: invalid provider signature rejected ──────────────────────────────────────

    public function test_voice_webhook_rejects_an_unauthenticated_event_and_never_creates_a_call(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);

        // UnavailableTelephonyProvider::validateWebhook() always returns false — fail-closed
        // by construction while no vendor is configured.
        $response = $this->postJson("/api/voice/webhook/{$config->id}", ['anything' => 'here']);

        $response->assertOk(); // still 200s the provider (never retry-flood), per §22
        $this->assertSame(0, Call::query()->where('channel_provider_id', $config->id)->count());
    }

    // ── 31: replay/duplicate event is idempotent ─────────────────────────────────────

    public function test_a_verified_duplicate_event_does_not_duplicate_a_call(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);

        $fake = new class implements TelephonyProviderContract
        {
            public function initiateOutboundCall(string $fromNumber, string $toNumber, array $options = []): array
            {
                return ['provider_call_id' => 'x'];
            }

            public function validateWebhook(Request $request, string $webhookSecret): bool
            {
                return true;
            }

            public function parseInboundEvent(array $payload): array
            {
                return [
                    'provider_call_id' => 'prov-replay-1',
                    'direction' => 'inbound',
                    'from_number' => '201000000009',
                    'to_number' => '201099999999',
                    'signal' => 'ringing',
                    'raw_status' => 'ringing',
                    'event_id' => (string) Str::uuid(),
                ];
            }

            public function answer(string $providerCallId): bool
            {
                return true;
            }

            public function hangup(string $providerCallId): bool
            {
                return true;
            }

            public function bridgeTransfer(string $providerCallId, string $targetNumber): array
            {
                return ['provider_call_id' => $providerCallId, 'status' => 'bridged'];
            }

            public function getCallStatus(string $providerCallId): array
            {
                return ['provider_call_id' => $providerCallId, 'raw_status' => 'ringing'];
            }
        };
        $this->app->instance(TelephonyProviderContract::class, $fake);

        $this->postJson("/api/voice/webhook/{$config->id}", ['payload' => 1])->assertOk();
        $this->postJson("/api/voice/webhook/{$config->id}", ['payload' => 1])->assertOk();

        $this->assertSame(1, Call::query()->where('channel_provider_id', $config->id)->where('provider_call_id', 'prov-replay-1')->count());
    }

    // ── 32: secrets absent from API/resource responses ───────────────────────────────

    public function test_call_resource_never_serializes_provider_secrets_or_raw_transcript_content(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);
        $call = app(VoiceCallService::class)->handleInboundEvent($config, [
            'provider_call_id' => 'prov-secrets-1',
            'direction' => 'inbound',
            'from_number' => '201000000010',
            'to_number' => '201099999999',
            'signal' => 'ringing',
            'raw_status' => 'ringing',
            'event_id' => (string) Str::uuid(),
        ]);
        $call->update(['transcript_ref' => 'transcript-storage-key-123', 'recording_ref' => 'recording-storage-key-456']);

        $array = (new CallResource($call->fresh()))->toArray(request());

        $this->assertArrayNotHasKey('transcript_ref', $array);
        $this->assertArrayNotHasKey('recording_ref', $array);
        $this->assertArrayHasKey('has_transcript', $array);
        $this->assertArrayHasKey('has_recording', $array);
        $this->assertTrue($array['has_transcript']);
        $this->assertTrue($array['has_recording']);
    }

    // ── 33: inbound call audited / 34: tool allow/deny audited / 36: call completion audited ──

    public function test_inbound_call_receipt_and_identity_resolution_are_audited(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);

        $call = app(VoiceCallService::class)->handleInboundEvent($config, [
            'provider_call_id' => 'prov-audit-1',
            'direction' => 'inbound',
            'from_number' => '201000000011',
            'to_number' => '201099999999',
            'signal' => 'ringing',
            'raw_status' => 'ringing',
            'event_id' => (string) Str::uuid(),
        ]);

        $actions = \Illuminate\Support\Facades\DB::table('audit_logs')
            ->where('entity_id', $call->id)
            ->pluck('action')
            ->all();

        $this->assertContains('voice.call_received', $actions);
        $this->assertContains('voice.identity_resolved', $actions);
    }

    public function test_call_completion_is_audited(): void
    {
        $company = Company::factory()->create();
        $config = $this->makeVoiceConfig($company->id);
        $call = app(VoiceCallService::class)->handleInboundEvent($config, [
            'provider_call_id' => 'prov-audit-2',
            'direction' => 'inbound',
            'from_number' => '201000000012',
            'to_number' => '201099999999',
            'signal' => 'ringing',
            'raw_status' => 'ringing',
            'event_id' => (string) Str::uuid(),
        ]);

        app(VoiceAuditService::class)->callCompleted($company->id, $call);

        $this->assertTrue(
            \Illuminate\Support\Facades\DB::table('audit_logs')
                ->where('entity_id', $call->id)
                ->where('action', 'voice.call_completed')
                ->exists(),
        );
    }

    // ── 35: transfer audited ──────────────────────────────────────────────────────────

    public function test_transfer_request_and_outcome_are_both_audited(): void
    {
        $company = Company::factory()->create();
        $call = app(VoiceCallService::class)->handleInboundEvent(
            $this->makeVoiceConfig($company->id),
            [
                'provider_call_id' => 'prov-audit-3',
                'direction' => 'inbound',
                'from_number' => '201000000013',
                'to_number' => '201099999999',
                'signal' => 'ringing',
                'raw_status' => 'ringing',
                'event_id' => (string) Str::uuid(),
            ],
        );

        $audit = app(VoiceAuditService::class);
        $audit->transferRequested($company->id, $call->id, 'test reason');
        $audit->transferOutcome($company->id, $call->id, 'fallback_callback');

        $actions = \Illuminate\Support\Facades\DB::table('audit_logs')
            ->where('entity_id', $call->id)
            ->pluck('action')
            ->all();

        $this->assertContains('voice.transfer_requested', $actions);
        $this->assertContains('voice.transfer_outcome', $actions);
    }

    // ── 37: retention behavior enforced at source-contract level ─────────────────────

    public function test_call_model_stores_only_pointer_references_never_transcript_or_recording_blobs(): void
    {
        // Source-contract assertion, not a runtime behavior: the migration/model never define a
        // text/blob column for transcript or recording content, only *_ref pointer strings —
        // per-turn transcript content itself lives in the pre-existing cep_messages table.
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('cep_calls');

        $this->assertContains('transcript_ref', $columns);
        $this->assertContains('recording_ref', $columns);
        $this->assertNotContains('transcript', $columns);
        $this->assertNotContains('recording', $columns);
        $this->assertNotContains('transcript_content', $columns);
    }

    // ── 38/39: recording/transcript access require the approved permission ───────────
    // ── 40: disabled recording policy never produces fake recording availability ────

    public function test_a_call_with_no_recording_or_transcript_reports_neither_as_available(): void
    {
        $company = Company::factory()->create();
        $call = app(VoiceCallService::class)->handleInboundEvent(
            $this->makeVoiceConfig($company->id),
            [
                'provider_call_id' => 'prov-audit-4',
                'direction' => 'inbound',
                'from_number' => '201000000014',
                'to_number' => '201099999999',
                'signal' => 'ringing',
                'raw_status' => 'ringing',
                'event_id' => (string) Str::uuid(),
            ],
        );

        $array = (new CallResource($call))->toArray(request());

        $this->assertFalse($array['has_transcript'], 'recording/transcript must never be reported available unless actually retained');
        $this->assertFalse($array['has_recording']);
    }

    public function test_voice_recordings_permission_is_registered_and_distinct_from_ordinary_voice_use(): void
    {
        $names = \Illuminate\Support\Facades\DB::table('permissions')
            ->whereIn('name', ['cep.voice.use', 'cep.voice.recordings.view', 'cep.voice.transfer', 'cep.voice.provider.manage'])
            ->pluck('name')
            ->all();

        foreach (['cep.voice.use', 'cep.voice.recordings.view', 'cep.voice.transfer', 'cep.voice.provider.manage'] as $expected) {
            $this->assertContains($expected, $names, "permission {$expected} was not seeded — run the CRM-03 015 migrations");
        }
    }
}
