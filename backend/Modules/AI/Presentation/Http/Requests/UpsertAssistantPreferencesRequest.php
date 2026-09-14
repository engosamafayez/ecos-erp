<?php

declare(strict_types=1);

namespace Modules\AI\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\AI\Domain\Enums\AssistantAvatar;
use Modules\AI\Domain\Enums\AssistantLanguage;
use Modules\AI\Domain\Enums\AssistantPersona;
use Modules\AI\Domain\Enums\AssistantSpeakingStyle;

/**
 * §6/§9 — server-side validation for the assistant personalization payload.
 * Every value is checked against a closed, stable-key enum (never a translated
 * label — §6A) before it reaches {@see \Modules\Core\UserPreferences\Application\
 * Services\UserPreferenceService}. Authorization (which user this affects) is
 * enforced by the controller, which always resolves the subject from
 * `$request->user()`, never from anything in this payload.
 */
final class UpsertAssistantPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'avatar_key' => ['required', 'string', Rule::in(AssistantAvatar::values())],
            // Empty string is allowed here (falls back to the locale-appropriate
            // default name in the controller) — trimmed to whitespace-only counts
            // as empty via prepareForValidation() above.
            'name' => ['nullable', 'string', 'max:40'],
            'persona' => ['required', 'string', Rule::in(AssistantPersona::values())],
            'speaking_style' => ['required', 'string', Rule::in(AssistantSpeakingStyle::values())],
            'language' => ['required', 'string', Rule::in(AssistantLanguage::values())],
            // CTO scope override (same task, ticket 046 — voice), FINAL CLOSURE §2 —
            // voice input (mic/STT) and spoken output (TTS) are independent
            // booleans; Wake by Name depends only on voice_input_enabled (enforced
            // in AssistantPreferences::fromPayload(), not here — this request only
            // validates shape, never cross-field policy).
            'voice_input_enabled' => ['nullable', 'boolean'],
            'spoken_responses_enabled' => ['nullable', 'boolean'],
            'wake_by_name_enabled' => ['nullable', 'boolean'],
            // No enum here: Web Speech voice names/URIs are assigned by the
            // browser/OS, not ECOS, so there is no fixed, enumerable set to
            // validate against server-side — only a bounded length. `voice_choice`
            // is best-effort and re-validated for actual availability client-side
            // on every device (see AssistantPreferences::fromPayload's own docblock).
            'voice_choice' => ['nullable', 'string', 'max:100'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'avatar_key.in' => 'That avatar is not a recognized ECOS assistant avatar.',
            'name.max' => 'The assistant name must be 40 characters or fewer.',
            'persona.in' => 'That presentation option is not supported.',
            'speaking_style.in' => 'That speaking style is not supported.',
            'language.in' => 'That language option is not supported.',
        ];
    }
}
