<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The minimum safe identity projection for "somebody to newly address" (CTO ruling,
 * TASK-ECOS-COLLABORATION-WORKSPACE-DRIVER-EXPOSURE-CLOSURE-005): id, name, and
 * whether they are driver-linked — nothing else from `App\Models\User` crosses this
 * boundary. `job_title` is the one deliberate addition (architecture report §15):
 * low-sensitivity, already the exact field the search itself matches against
 * (UserRepository::query()), so surfacing it costs nothing new the caller couldn't
 * already find by searching for it — unlike email/phone/role, still withheld.
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
            'job_title' => $this->job_title,
            'is_driver' => (bool) $this->getAttribute('is_driver'),
        ];
    }
}
