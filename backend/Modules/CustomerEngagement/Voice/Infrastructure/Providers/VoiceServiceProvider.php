<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Infrastructure\Providers;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Modules\AI\Application\Services\AIAuditService;
use Modules\AI\Application\Tools\GetCustomerBalanceTool;
use Modules\AI\Application\Tools\GetCustomerOrdersTool;
use Modules\AI\Application\Tools\GetCustomerSummaryTool;
use Modules\AI\Application\Tools\GetOrderPaymentProofStateTool;
use Modules\AI\Application\Tools\GetOrderSummaryTool;
use Modules\AI\Application\Tools\GetStockAvailabilityTool;
use Modules\CustomerEngagement\Voice\Application\Contracts\RealtimeVoiceProviderContract;
use Modules\CustomerEngagement\Voice\Application\Contracts\TelephonyProviderContract;
use Modules\CustomerEngagement\Voice\Application\Services\CallerVerificationService;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceAIToolInvoker;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceAIToolRegistry;
use Modules\CustomerEngagement\Voice\Application\Services\VoiceToolCatalogService;
use Modules\CustomerEngagement\Voice\Application\Tools\CreateFollowUpTool;
use Modules\CustomerEngagement\Voice\Application\Tools\CreateSupportTicketTool;
use Modules\CustomerEngagement\Voice\Application\Tools\ScheduleCallbackTool;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 — registers the Voice
 * backend foundation. Mirrors AIServiceProvider's own structure/ordering rationale (registers
 * after IAM/Commerce/Crm/Inventory/AI, all already registered earlier in
 * bootstrap/providers.php, since VoiceAIToolRegistry's tool list depends on several of them).
 */
final class VoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TelephonyProviderContract::class, function (Application $app): TelephonyProviderContract {
            $driver = config('voice.telephony.driver');

            return match ($driver) {
                // No concrete vendor selected in this task (architecture report, EXTERNAL
                // DEPENDENCIES) — do not invent one; every unrecognized/unset driver value
                // fails closed the same way.
                default => new UnavailableTelephonyProvider,
            };
        });

        $this->app->bind(RealtimeVoiceProviderContract::class, function (Application $app): RealtimeVoiceProviderContract {
            $driver = config('voice.realtime.driver');

            return match ($driver) {
                default => new UnavailableRealtimeVoiceProvider,
            };
        });

        $this->app->singleton(VoiceAIToolRegistry::class, function (Application $app): VoiceAIToolRegistry {
            return new VoiceAIToolRegistry([
                // Reused CORE-03 tools, unmodified (architecture report, TOOLS — READ).
                $app->make(GetCustomerSummaryTool::class),
                $app->make(GetCustomerOrdersTool::class),
                $app->make(GetOrderSummaryTool::class),
                $app->make(GetOrderPaymentProofStateTool::class),
                $app->make(GetStockAvailabilityTool::class),
                // Registered but only ever OFFERED post-verification — see
                // VoiceToolCatalogService. The invoker's own permission/scope checks are the
                // hard gate regardless of what was offered.
                $app->make(GetCustomerBalanceTool::class),
                // Voice-specific LOW-RISK CONFIRMED ACTION tools (§12).
                $app->make(CreateFollowUpTool::class),
                $app->make(ScheduleCallbackTool::class),
                $app->make(CreateSupportTicketTool::class),
            ]);
        });

        // §10 — 'cep.voice.use', never 'ai.assistant.use' (§35). Gap B (016 §5): also wires the
        // catalogue + verification service the invoker needs to re-check verification at
        // execution time, independent of whatever a session was configured to offer.
        $this->app->bind(VoiceAIToolInvoker::class, function (Application $app): VoiceAIToolInvoker {
            return new VoiceAIToolInvoker(
                $app->make(VoiceAIToolRegistry::class),
                $app->make(AuthorizationGatewayInterface::class),
                $app->make(TenantOwnershipResolver::class),
                $app->make(AIAuditService::class),
                'cep.voice.use',
                $app->make(VoiceToolCatalogService::class),
                $app->make(CallerVerificationService::class),
                $app->make(AIAuditService::class),
            );
        });
    }
}
