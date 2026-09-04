<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use Illuminate\Support\Collection;
use Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface;
use Modules\IAM\Domain\Enums\RoleTemplateStatus;
use Modules\IAM\Domain\Exceptions\RoleTemplateInUseException;
use Modules\IAM\Domain\Exceptions\SystemTemplateImmutableException;
use Modules\IAM\Domain\Models\RoleTemplate;
use Modules\IAM\Domain\Models\UserTemplateAssignment;

/**
 * Persistence for the Role Template library (ADR-039).
 * Enforces system-template immutability and append-only versioning.
 *
 * TASK-ECOS-IAM-SECURE-ADMIN-API-002: every mutator now leaves an audit trail (§18) via
 * RoleTemplateAuditService — the same generic App\Core\Audit\AuditService UserAuditService
 * already uses, per a thin entity-scoped facade. delete() now refuses a template isInUse()
 * reports as held by any user (D13); archive() is the safe alternative. createCustom()/clone()
 * are company-scoped (D11) — system templates stay global, unaffected.
 */
class RoleTemplateRepository implements RoleTemplateRepositoryInterface
{
    public function __construct(
        private readonly RoleTemplateVersionService $versions,
        private readonly RoleTemplateAuditService $audit,
    ) {}

    public function all(): Collection
    {
        return RoleTemplate::query()->orderBy('category')->orderBy('name')->get();
    }

    public function forCategory(string $category): Collection
    {
        return RoleTemplate::query()->where('category', $category)->orderBy('name')->get();
    }

    public function systemTemplates(): Collection
    {
        return RoleTemplate::query()->where('is_system', true)->orderBy('name')->get();
    }

    public function customTemplates(): Collection
    {
        return RoleTemplate::query()->where('is_system', false)->orderBy('name')->get();
    }

    public function customTemplatesForCompany(string $companyId): Collection
    {
        return RoleTemplate::query()
            ->where('is_system', false)
            ->where('company_id', $companyId)
            ->orderBy('name')
            ->get();
    }

    public function findByKey(string $key): ?RoleTemplate
    {
        return RoleTemplate::query()->where('key', $key)->first();
    }

    public function isInUse(RoleTemplate $template): bool
    {
        return UserTemplateAssignment::where('role_template_id', $template->id)->exists();
    }

    public function upsertSystem(string $key, array $attributes): RoleTemplate
    {
        $template = RoleTemplate::firstOrNew(['key' => $key]);
        $existed = $template->exists;
        $definitionChanged = $existed && $template->definition != ($attributes['definition'] ?? []);

        $template->fill([
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'category' => $attributes['category'],
            'is_composable' => $attributes['is_composable'] ?? true,
            'definition' => $attributes['definition'] ?? [],
        ]);
        $template->is_system = true;
        $template->status = RoleTemplateStatus::PUBLISHED->value;

        if (! $existed) {
            $template->version = 1;
            $template->published_at = now();
        } elseif ($definitionChanged) {
            $template->version = $template->version + 1;
            $template->published_at = now();
        }

        $template->save();
        $this->versions->snapshot($template, $existed ? 'system template updated' : 'initial system template');

        return $template->refresh();
    }

    /**
     * @param  array<string,mixed>  $attributes  'company_id' is D11 tenant ownership — every
     *                                           caller reachable from the Admin API must supply
     *                                           it (server-derived, never client-selected,
     *                                           matching the D2 pattern for Users); left
     *                                           nullable at the schema/service level so the
     *                                           pre-existing RoleTemplateImportService caller
     *                                           (out of this task's HTTP-wiring scope) keeps
     *                                           working unchanged.
     */
    public function createCustom(array $attributes): RoleTemplate
    {
        $template = new RoleTemplate([
            'key' => $attributes['key'],
            'name' => $attributes['name'],
            'description' => $attributes['description'] ?? null,
            'category' => $attributes['category'],
            'is_composable' => $attributes['is_composable'] ?? true,
            'definition' => $attributes['definition'] ?? [],
            'created_by' => $attributes['created_by'] ?? null,
            'company_id' => $attributes['company_id'] ?? null,
        ]);
        $template->is_system = false;
        $template->status = RoleTemplateStatus::DRAFT->value;
        $template->version = 1;
        $template->save();

        $this->versions->snapshot($template, 'custom template created', $attributes['created_by'] ?? null);
        $this->audit->logTemplate('created', $template, [], ['key' => $template->key, 'company_id' => $template->company_id]);

        return $template->refresh();
    }

    public function update(RoleTemplate $template, array $attributes, ?string $changeNote = null): RoleTemplate
    {
        $this->guardSystem($template);

        $old = $template->only(['name', 'description', 'category', 'is_composable', 'definition', 'status']);

        $template->fill(array_intersect_key($attributes, array_flip([
            'name', 'description', 'category', 'is_composable', 'definition', 'status', 'updated_by',
        ])));
        $template->version = $template->version + 1;
        $template->save();

        $this->versions->snapshot($template, $changeNote ?? 'template updated', $attributes['updated_by'] ?? null);
        $this->audit->logTemplate('updated', $template, $old, $template->only(['name', 'description', 'category', 'is_composable', 'definition', 'status']), ['version' => $template->version]);

        return $template->refresh();
    }

    public function clone(RoleTemplate $template, string $newKey, string $companyId, ?string $newName = null): RoleTemplate
    {
        $clone = $this->createCustom([
            'key' => $newKey,
            'name' => $newName ?? ('Copy of '.$template->name),
            'description' => $template->description,
            'category' => $template->category,
            'is_composable' => $template->is_composable,
            'definition' => $template->definition,
            'company_id' => $companyId,
        ]);

        $this->audit->logTemplate('cloned', $clone, [], ['cloned_from' => $template->key]);

        return $clone;
    }

    /** D13 preferred lifecycle path: ACTIVE → ARCHIVED, reusing the existing status column. */
    public function archive(RoleTemplate $template, ?string $changeNote = null): RoleTemplate
    {
        return $this->update($template, ['status' => RoleTemplateStatus::ARCHIVED->value], $changeNote ?? 'template archived');
    }

    /**
     * @throws RoleTemplateInUseException when the template is currently assigned to any user (D13)
     */
    public function delete(RoleTemplate $template): void
    {
        $this->guardSystem($template);

        $assignmentCount = UserTemplateAssignment::where('role_template_id', $template->id)->count();
        if ($assignmentCount > 0) {
            throw RoleTemplateInUseException::forKey($template->key, $assignmentCount);
        }

        $this->audit->logTemplate('deleted', $template, ['key' => $template->key], []);
        $template->delete();
    }

    private function guardSystem(RoleTemplate $template): void
    {
        if ($template->is_system) {
            throw SystemTemplateImmutableException::forKey($template->key);
        }
    }
}
