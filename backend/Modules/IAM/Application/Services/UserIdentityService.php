<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Enums\UserStatus;

/**
 * Manages the enterprise identity — creation and identity-field edits with de-duplication
 * (ADR-040). A new user starts in DRAFT with an unusable random password; they cannot
 * authenticate until activated and a password is set via the invitation flow.
 */
class UserIdentityService
{
    /**
     * `company_id` is deliberately excluded (TASK-ECOS-IAM-SECURE-ADMIN-API-002, D2/D3 —
     * CTO-ratified). Tenant ownership is server-derived on create and non-writable on
     * update; a client-supplied value in $data is never read by fill(), by construction.
     */
    private const IDENTITY_FIELDS = [
        'name', 'display_name', 'email', 'username', 'employee_number', 'phone', 'avatar_path',
    ];

    public function __construct(private readonly UserAuditService $audit) {}

    /**
     * @param  array<string,mixed>  $data
     * @param  string  $companyId  server-derived tenant ownership (D2) — never taken from $data
     */
    public function createDraft(array $data, string $companyId, ?int $actorId = null): User
    {
        $this->assertUniqueIdentity($data, null);

        $user = new User();
        $user->fill(array_intersect_key($data, array_flip(self::IDENTITY_FIELDS)));
        $user->company_id = $companyId;
        $user->password = Hash::make(Str::random(40)); // unusable until set via invitation
        $user->status = UserStatus::DRAFT->value;
        $user->created_by = $actorId;
        $user->save();

        $this->audit->log('created', $user, [], ['email' => $user->email, 'status' => $user->status, 'company_id' => $companyId]);

        return $user;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public function updateIdentity(User $user, array $data, ?int $actorId = null): User
    {
        $this->assertUniqueIdentity($data, $user);

        $old = $user->only(self::IDENTITY_FIELDS);
        $user->fill(array_intersect_key($data, array_flip(self::IDENTITY_FIELDS)));
        $user->save();

        $this->audit->log('identity_updated', $user, $old, $user->only(self::IDENTITY_FIELDS));

        return $user;
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function assertUniqueIdentity(array $data, ?User $ignore): void
    {
        foreach (['email', 'username', 'employee_number'] as $field) {
            if (empty($data[$field])) {
                continue;
            }
            $exists = User::withTrashed()
                ->where($field, $data[$field])
                ->when($ignore !== null, fn ($q) => $q->where('id', '!=', $ignore->getKey()))
                ->exists();
            if ($exists) {
                throw new \InvalidArgumentException("A user with this {$field} already exists.");
            }
        }
    }
}
