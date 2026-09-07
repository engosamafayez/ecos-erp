<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates the login payload.
 *
 * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §7: username is a valid login identifier
 * alongside email, so `email` can no longer carry the `email` validation rule — it would
 * reject every username before the credential check was ever reached.
 *
 * The wire contract is unchanged and backwards compatible. An existing client posting
 * `{ email, password }` keeps working exactly as before; a client may instead post
 * `identifier` (or `username`). prepareForValidation() normalises whichever arrived into
 * `identifier`, so `rules()` has one required field to validate and LoginDTO has one field
 * to read.
 *
 * Deliberately NOT done here: any attempt to detect "is this an email or a username" and
 * branch. That decision belongs to the credential lookup
 * (SanctumAuthService::attemptCredentials(), which matches either column in one query),
 * and duplicating it here would be a second identifier policy that could drift from the
 * first. `max:255` matches the column width for both `users.email` and `users.username`.
 */
final class LoginRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $identifier = $this->input('identifier')
            ?? $this->input('email')
            ?? $this->input('username');

        $this->merge([
            'identifier' => is_string($identifier) ? trim($identifier) : $identifier,
        ]);
    }

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifier.required' => 'Enter your email address or username.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'identifier' => 'email or username',
        ];
    }
}
