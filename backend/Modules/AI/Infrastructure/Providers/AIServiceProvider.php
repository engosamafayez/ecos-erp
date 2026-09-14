<?php

declare(strict_types=1);

namespace Modules\AI\Infrastructure\Providers;

use App\Core\AI\Contracts\AIProviderInterface;
use App\Core\AI\Providers\DisabledAIProvider;
use App\Core\AI\Providers\OpenAIProvider;
use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Modules\AI\Application\Services\AIAssistantService;
use Modules\AI\Application\Services\AIAuditService;
use Modules\AI\Application\Services\AIToolInvoker;
use Modules\AI\Application\Services\AIToolRegistry;
use Modules\AI\Application\Services\SystemPolicyBuilder;
use Modules\AI\Application\Tools\GetCustomerBalanceTool;
use Modules\AI\Application\Tools\GetCustomerOrdersTool;
use Modules\AI\Application\Tools\GetCustomerSummaryTool;
use Modules\AI\Application\Tools\GetOrderPaymentProofStateTool;
use Modules\AI\Application\Tools\GetOrderSummaryTool;
use Modules\AI\Application\Tools\GetReportsCatalogueTool;
use Modules\AI\Application\Tools\GetStockAvailabilityTool;
use Modules\AI\Application\Tools\RunReportTool;
use Modules\AI\Application\Tools\SearchAuditLogTool;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;

/**
 * Registers Resident AI (CORE-03). Depends on IAM (authorization), Reporting
 * (RunReportTool/GetReportsCatalogueTool), Commerce\Orders, Crm\Customers,
 * Finance\Receivables and Inventory\Products (their respective tools) — all
 * already registered earlier in bootstrap/providers.php — so this registers
 * last, mirroring ReportingServiceProvider's own ordering rationale.
 */
final class AIServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AIProviderInterface::class, function (Application $app): AIProviderInterface {
            if (! (bool) config('ai.enabled', false)) {
                return new DisabledAIProvider;
            }

            $providerName = (string) config('ai.provider', 'openai');

            return match ($providerName) {
                'openai' => new OpenAIProvider(
                    apiKey: config('ai.providers.openai.api_key'),
                    model: (string) config('ai.providers.openai.model'),
                    baseUrl: (string) config('ai.providers.openai.base_url'),
                    timeoutSeconds: (int) config('ai.timeout_seconds'),
                    maxResponseTokens: (int) config('ai.max_response_tokens'),
                ),
                default => new DisabledAIProvider,
            };
        });

        $this->app->singleton(AIToolRegistry::class, function (Application $app): AIToolRegistry {
            return new AIToolRegistry([
                $app->make(GetReportsCatalogueTool::class),
                $app->make(RunReportTool::class),
                $app->make(SearchAuditLogTool::class),
                $app->make(GetOrderSummaryTool::class),
                $app->make(GetOrderPaymentProofStateTool::class),
                $app->make(GetCustomerSummaryTool::class),
                $app->make(GetCustomerOrdersTool::class),
                $app->make(GetStockAvailabilityTool::class),
                $app->make(GetCustomerBalanceTool::class),
            ]);
        });

        // TASK-...-CRM-03-...-015 §10 — AIToolInvoker's entry permission is now a constructor
        // argument (previously hardcoded 'ai.assistant.use' inside invoke() itself); this
        // explicit binding preserves CORE-03's exact prior behavior. Voice's own invoker
        // (VoiceServiceProvider) passes 'cep.voice.use' instead — never this one (§35).
        $this->app->bind(AIToolInvoker::class, function (Application $app): AIToolInvoker {
            return new AIToolInvoker(
                $app->make(AIToolRegistry::class),
                $app->make(AuthorizationGatewayInterface::class),
                $app->make(TenantOwnershipResolver::class),
                $app->make(AIAuditService::class),
                'ai.assistant.use',
            );
        });

        $this->app->bind(AIAssistantService::class, function (Application $app): AIAssistantService {
            return new AIAssistantService(
                provider: $app->make(AIProviderInterface::class),
                registry: $app->make(AIToolRegistry::class),
                invoker: $app->make(AIToolInvoker::class),
                policy: $app->make(SystemPolicyBuilder::class),
                audit: $app->make(AIAuditService::class),
                authorization: $app->make(AuthorizationGatewayInterface::class),
                maxToolCalls: (int) config('ai.max_tool_calls_per_request', 4),
            );
        });
    }
}
