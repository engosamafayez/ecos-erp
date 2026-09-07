<?php

declare(strict_types=1);

namespace Modules\IAM\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Save a Role's per-item navigation VISIBILITY overrides
 * (User-review remediation, Batch 02, item I).
 *
 * `overrides` is the COMPLETE desired map, matching `UpdateRolePermissionsRequest`'s own
 * "present, not required, and it's the whole picture" convention — clearing every override
 * back to "inherit everything" is legitimate and is expressed as an empty object.
 *
 * Shape validation only: a key is any non-empty string (the actual nav-item-key universe
 * lives in the frontend's `module-navigation.ts` registry, not duplicated here) and a value
 * is one of the two states the UI ever writes. This never checks whether the actor's OWN
 * permissions cover the item, because there is no security property to protect — see the
 * migration's docblock: this column is never read by any authorization check.
 */
final class UpdateRoleNavigationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'overrides' => ['present', 'array'],
            'overrides.*' => ['required', 'string', 'in:visible,hidden'],
        ];
    }
}
