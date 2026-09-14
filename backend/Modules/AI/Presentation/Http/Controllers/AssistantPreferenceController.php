<?php

declare(strict_types=1);

namespace Modules\AI\Presentation\Http\Controllers;

use App\Models\User;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\AI\Application\ValueObjects\AssistantPreferences;
use Modules\AI\Presentation\Http\Requests\UpsertAssistantPreferencesRequest;
use Modules\Core\UserPreferences\Application\Services\UserPreferenceService;

/**
 * TASK-ECOS-V1.1-FINAL-AI-ASSISTANT-PERSONALIZED-COMPANION-046 §9 — persists
 * assistant personalization through the EXISTING, canonical user-preferences
 * authority ({@see UserPreferenceService}, table `user_preferences`), under its
 * own category key `ai_assistant`. No second preferences table, no second
 * persistence engine — this controller adds only the domain-specific validation
 * (see {@see UpsertAssistantPreferencesRequest}) the generic `/me/preferences/
 * {category}` endpoint explicitly leaves to "the consuming module" (see that
 * endpoint's own UpsertCategoryPreferencesRequest docblock).
 *
 * The authenticated user is always the implicit subject — never a client-
 * supplied id (§9: "user A cannot modify user B preferences"). §7 — show()
 * always returns a COMPLETE, valid preference object (merging any stored
 * payload over locale-appropriate defaults) rather than a 404 a new user would
 * have to special-case client-side.
 */
final class AssistantPreferenceController extends Controller
{
    use HasApiResponse;

    /** Reuses Modules\Core\UserPreferences' own category namespace — see PreferenceCategory::AiAssistant. */
    private const CATEGORY = 'ai_assistant';

    public function __construct(private readonly UserPreferenceService $preferences) {}

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $record = $this->preferences->getByCategory((int) $user->id, self::CATEGORY);
        $prefs = AssistantPreferences::fromPayload($record?->payload ?? [], $this->userLocale($user));

        return $this->success($prefs->toArray());
    }

    public function upsert(UpsertAssistantPreferencesRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $prefs = AssistantPreferences::fromPayload($request->validated(), $this->userLocale($user));
        $record = $this->preferences->upsert((int) $user->id, self::CATEGORY, $prefs->toArray());

        return $this->success($record->payload, 'Assistant preferences saved.');
    }

    private function userLocale(User $user): string
    {
        $locale = $user->locale ?? null;

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }
}
