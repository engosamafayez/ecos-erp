<?php

declare(strict_types=1);

namespace Modules\AI\Presentation\Http\Controllers;

use App\Core\AI\ValueObjects\AIProviderMessage;
use App\Core\Company\TenantOwnershipResolver;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\AI\Application\Services\AIAssistantService;
use Modules\AI\Application\ValueObjects\AssistantPreferences;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Presentation\Http\Requests\AssistantMessageRequest;
use Modules\Core\UserPreferences\Application\Services\UserPreferenceService;

/**
 * §25 — the one bounded, non-streaming HTTP surface for CORE-03 Task 2's
 * frontend. Never exposes provider internals; company/user identity are always
 * resolved from the authenticated request via the same TenantOwnershipResolver
 * every other module uses, never trusted from the payload (§6/§7).
 *
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §12 — also reads
 * the user's own assistant preferences (same UserPreferenceService,
 * category 'ai_assistant', that AssistantPreferenceController writes) so
 * SystemPolicyBuilder can apply them as presentation-only guidance. A user who
 * never personalized anything resolves to the exact same locale-based defaults
 * AssistantPreferenceController::show() would return — never a second default
 * source.
 */
final class AssistantController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly AIAssistantService $assistant,
        private readonly TenantOwnershipResolver $tenant,
        private readonly UserPreferenceService $preferences,
    ) {}

    public function message(AssistantMessageRequest $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $this->tenant->companyId();

        if ($companyId === null) {
            abort(403, 'The AI assistant requires a definite company context; none is available for this account.');
        }

        $locale = is_string($user->locale ?? null) && $user->locale !== '' ? $user->locale : app()->getLocale();
        $preferenceRecord = $this->preferences->getByCategory((int) $user->id, 'ai_assistant');
        $assistantPrefs = AssistantPreferences::fromPayload($preferenceRecord?->payload ?? [], $locale);

        $context = new AIRequestContext(
            userId: $user->id,
            companyId: $companyId,
            brandId: $request->string('brand_id')->value() ?: null,
            locale: app()->getLocale(),
            route: $request->string('route')->value() ?: null,
            module: $request->string('module')->value() ?: null,
            page: $request->string('page')->value() ?: null,
            entityType: $request->string('entity_type')->value() ?: null,
            entityId: $request->string('entity_id')->value() ?: null,
            assistantName: $assistantPrefs->name,
            assistantPersona: $assistantPrefs->persona,
            assistantSpeakingStyle: $assistantPrefs->speakingStyle,
            assistantLanguage: $assistantPrefs->language,
        );

        $history = array_map(
            static fn (array $turn): AIProviderMessage => $turn['role'] === 'assistant'
                ? AIProviderMessage::assistant($turn['content'])
                : AIProviderMessage::user($turn['content']),
            $request->array('history'),
        );

        $response = $this->assistant->handle($context, $user, $request->string('message')->value(), $history);

        return $this->success($response->toArray());
    }
}
