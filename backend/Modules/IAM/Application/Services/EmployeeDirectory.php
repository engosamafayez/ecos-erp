<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Employee lookup for the user Create/Edit workflow
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §6).
 *
 * §6 replaces manual Employee Number entry with a searchable, selectable lookup over
 * EXISTING employee records, and forbids building "a duplicate employee directory".
 *
 * So this reads `hr_employees` — the HR module's own workforce single source of truth —
 * directly, and writes nothing. It is a narrow read-only projection (id, employee number,
 * name, job/status, linked user) behind the IAM administrator's own `iam.users.view`
 * token, for the same reason OrganizationScopeDirectory reads the org tables: the HR
 * endpoint is gated on `hr.employees.view`, and requiring every IAM administrator to also
 * hold an HR permission in order to fill in one lookup field would mean permission
 * inflation on the IAM role.
 *
 * THE RELATIONSHIP AUTHORITY IS UNCHANGED. `hr_employees.user_id` is and remains the
 * canonical employee↔user link, and `users.employee_number` stays the identity column
 * `UserIdentityService::assertUniqueIdentity()` already enforces uniqueness on. Selecting
 * an employee in the UI resolves to that employee's real `employee_number`, which is what
 * gets written — so the link is by canonical data, not by a new IAM-side join table.
 *
 * DEV STATE, RECORDED HONESTLY: `hr_employees` has zero rows on DEV at implementation
 * time. The lookup is therefore fully wired and will return results the moment employees
 * exist, and until then it renders its empty state. It does NOT fall back to free-text
 * entry, because that is the defect §6 exists to remove.
 */
class EmployeeDirectory
{
    private const TABLE = 'hr_employees';

    public function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * Search employees available for linking.
     *
     * @param  bool  $onlyUnlinked  hide employees already attached to a user account —
     *                              the default for a CREATE flow, relaxed on EDIT so the
     *                              currently-linked employee still resolves.
     * @return list<array<string,mixed>>
     */
    public function search(?string $term = null, ?string $companyId = null, bool $onlyUnlinked = false, int $limit = 50): array
    {
        if (! $this->available()) {
            return [];
        }

        $query = DB::table(self::TABLE)
            ->select([
                'id', 'employee_number', 'first_name', 'last_name', 'display_name',
                'work_email', 'phone', 'mobile', 'status', 'user_id', 'company_id',
                'branch_id', 'department_id',
            ]);

        if (Schema::hasColumn(self::TABLE, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        if ($companyId !== null) {
            $query->where('company_id', $companyId);
        }

        if ($onlyUnlinked) {
            $query->whereNull('user_id');
        }

        if ($term !== null && $term !== '') {
            $like = '%'.$term.'%';
            $query->where(function ($q) use ($like) {
                $q->where('employee_number', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('display_name', 'like', $like)
                    ->orWhere('work_email', 'like', $like);
            });
        }

        return $query
            ->orderBy('employee_number')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => $this->present($row))
            ->values()
            ->all();
    }

    /** One employee by primary key, or null. @return array<string,mixed>|null */
    public function find(string|int $id): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $row = DB::table(self::TABLE)->where('id', $id)->first();

        return $row !== null ? $this->present($row) : null;
    }

    /** One employee by their canonical employee number, or null. @return array<string,mixed>|null */
    public function findByNumber(string $employeeNumber): ?array
    {
        if (! $this->available()) {
            return null;
        }

        $row = DB::table(self::TABLE)->where('employee_number', $employeeNumber)->first();

        return $row !== null ? $this->present($row) : null;
    }

    /**
     * Does an employee with this number exist? Used to validate a submitted employee link
     * server-side rather than trusting whatever the client typed.
     */
    public function numberExists(string $employeeNumber): bool
    {
        if (! $this->available()) {
            return false;
        }

        return DB::table(self::TABLE)->where('employee_number', $employeeNumber)->exists();
    }

    /** @return array<string,mixed> */
    private function present(object $row): array
    {
        $name = trim((string) ($row->display_name ?? ''));

        if ($name === '') {
            $name = trim(((string) ($row->first_name ?? '')).' '.((string) ($row->last_name ?? '')));
        }

        return [
            'id' => (string) $row->id,
            'employee_number' => $row->employee_number !== null ? (string) $row->employee_number : null,
            'name' => $name,
            'work_email' => $row->work_email !== null ? (string) $row->work_email : null,
            'phone' => $row->phone !== null ? (string) $row->phone : (($row->mobile ?? null) !== null ? (string) $row->mobile : null),
            'status' => $row->status !== null ? (string) $row->status : null,
            'company_id' => $row->company_id !== null ? (string) $row->company_id : null,
            'branch_id' => $row->branch_id !== null ? (string) $row->branch_id : null,
            'department_id' => $row->department_id !== null ? (string) $row->department_id : null,
            // Already attached to a user account — the UI marks these so an administrator
            // cannot silently double-link one employee to two logins.
            'linked_user_id' => $row->user_id !== null ? (int) $row->user_id : null,
        ];
    }
}
