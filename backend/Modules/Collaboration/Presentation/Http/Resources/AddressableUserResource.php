<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The minimum safe identity projection for "somebody to newly address" (CTO ruling,
 * TASK-ECOS-COLLABORATION-WORKSPACE-DRIVER-EXPOSURE-CLOSURE-005): id, name, and
 * whether they are driver-linked — nothing else from `App\Models\User` (no email,
 * phone, role, or any other field) crosses this boundary.
 *
 * @mixin \App\Models\User
 */
final class AddressableUserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_driver' => (bool) $this->getAttribute('is_driver'),
        ];
    }
}
