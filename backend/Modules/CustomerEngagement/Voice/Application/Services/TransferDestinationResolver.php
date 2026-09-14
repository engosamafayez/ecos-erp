<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use App\Models\User;
use Modules\CustomerEngagement\Domain\Models\Conversation;
use Modules\CustomerEngagement\Voice\Domain\Models\VoiceTeamDestination;
use Modules\CustomerEngagement\Voice\Domain\ValueObjects\TransferDestination;
use Modules\Hr\Workforce\Domain\Enums\EmployeeStatus;
use Modules\Hr\Workforce\Domain\Models\Employee;
use Modules\IAM\Domain\Enums\UserStatus;
use Modules\Sales\Customers\Domain\Services\PhoneNormalizer;

/**
 * TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §2/§3 — Gap A closure. The ONE
 * place an assigned employee/team becomes a real, validated, dialable TransferDestination —
 * never a bare internal id.
 *
 * `cep_conversations.assigned_employee_id` is typed `uuid` in its own migration but is actually
 * written/read as a `users.id` bigint everywhere it is set (RoutingService::autoRoute() assigns
 * it directly from RoutingRule.assign_to_user_id, itself an unsignedBigInteger) — a pre-existing
 * naming/typing inconsistency, not introduced here. This resolver treats it as what it actually
 * is: a users.id.
 *
 * Individual employee phone: hr_employees (Employee.user_id -> users.id), the canonical,
 * single-source-of-truth workforce record ("ONE PERSON · ONE RECORD · REFERENCED BY EVERY
 * MODULE" — Employee's own docblock) — falls back to User.phone only if no Employee row or the
 * Employee has no phone/mobile. No second employee-phone authority was created.
 *
 * Team phone: cep_voice_team_destinations (new, additive — teams have no existing phone concept
 * anywhere in this codebase).
 */
final class TransferDestinationResolver
{
    private const MIN_DIALABLE_DIGITS = 10;

    public function __construct(private readonly PhoneNormalizer $phoneNormalizer) {}

    public function resolveForConversation(Conversation $conversation): ?TransferDestination
    {
        if ($conversation->assigned_employee_id !== null) {
            return $this->resolveEmployee($conversation->company_id, (string) $conversation->assigned_employee_id);
        }

        if ($conversation->assigned_team_id !== null) {
            return $this->resolveTeam($conversation->company_id, $conversation->brand_id, (string) $conversation->assigned_team_id);
        }

        return null;
    }

    private function resolveEmployee(string $companyId, string $userIdRaw): ?TransferDestination
    {
        if (! ctype_digit($userIdRaw)) {
            return null;
        }

        // Company scope enforced here — a user belonging to a different company never resolves,
        // regardless of what the Conversation's own assigned_employee_id claims.
        $user = User::query()->where('company_id', $companyId)->where('status', UserStatus::ACTIVE->value)->find((int) $userIdRaw);

        if ($user === null) {
            return null;
        }

        $employee = Employee::query()->where('user_id', $user->id)->first();

        if ($employee !== null && $employee->status !== EmployeeStatus::Active) {
            return null;
        }

        $rawPhone = $employee?->phone ?: $employee?->mobile ?: $user->phone;
        $dialable = $this->toDialable($rawPhone);

        if ($dialable === null) {
            return null;
        }

        return new TransferDestination('employee', (string) $user->id, $dialable);
    }

    private function resolveTeam(string $companyId, ?string $brandId, string $teamId): ?TransferDestination
    {
        $destination = VoiceTeamDestination::query()
            ->where('team_id', $teamId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->where(function ($q) use ($brandId) {
                // A destination scoped to a specific Brand only serves that Brand; a
                // company-wide destination (brand_id null) serves every Brand — never the
                // reverse (a Brand-specific destination must not leak to a different Brand).
                $q->whereNull('brand_id')->orWhere('brand_id', $brandId);
            })
            ->first();

        if ($destination === null) {
            return null;
        }

        $dialable = $this->toDialable($destination->phone_number);

        if ($dialable === null) {
            return null;
        }

        return new TransferDestination('team', $teamId, $dialable);
    }

    private function toDialable(?string $phone): ?string
    {
        $normalized = $this->phoneNormalizer->normalize($phone);

        return strlen($normalized) >= self::MIN_DIALABLE_DIGITS ? $normalized : null;
    }
}
