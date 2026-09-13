<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Contracts;

use Illuminate\Support\Collection;
use Modules\IAM\Domain\Models\RoleTemplate;

/**
 * Read/write access to the Role Template library (ADR-039). Write operations enforce
 * system-template immutability and append-only versioning.
 */
interface RoleTemplateRepositoryInterface
{
    /** @return Collection<int,RoleTemplate> */
    public function all(): Collection;

    /** @return Collection<int,RoleTemplate> */
    public function forCategory(string $category): Collection;

    /** @return Collection<int,RoleTemplate> */
    public function systemTemplates(): Collection;

    /** @return Collection<int,RoleTemplate> */
    public function customTemplates(): Collection;

    /**
     * Custom templates belonging to one company (D11 — tenant-scoped). System templates are
     * never returned here; they are global and reached only via systemTemplates()/findByKey().
     *
     * @return Collection<int,RoleTemplate>
     */
    public function customTemplatesForCompany(string $companyId): Collection;

    public function findByKey(string $key): ?RoleTemplate;

    /**
     * Whether any user currently holds this template (TASK-ECOS-IAM-SECURE-ADMIN-API-002, D13).
     * A true result means hard-delete must be refused — archive instead.
     */
    public function isInUse(RoleTemplate $template): bool;

    /**
     * Idempotently upsert a system template (used by the seeder).
     *
     * @param  array<string,mixed>  $attributes
     */
    public function upsertSystem(string $key, array $attributes): RoleTemplate;

    /**
     * Create a custom (non-system) template.
     *
     * CORE-02 Task 1 — every permission in `attributes['definition']['permissions']` is
     * checked against the Permission Grant Ceiling UNLESS it is also named in
     * $preauthorizedPermissions. That parameter exists solely for
     * RoleAuthoringService::adoptIntoTemplate(): mirroring a role's OWN current
     * `role_permissions` grants into a template is provably lossless (nothing new is granted,
     * the role already carries this exact access), so those specific names are pre-cleared
     * rather than exempted by a blanket bypass flag. Every other caller passes the default
     * empty list and is checked in full.
     *
     * @param  array<string,mixed>  $attributes
     * @param  list<string>  $preauthorizedPermissions
     */
    public function createCustom(array $attributes, array $preauthorizedPermissions = []): RoleTemplate;

    /**
     * Update a template's definition/metadata. Refuses system templates.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function update(RoleTemplate $template, array $attributes, ?string $changeNote = null): RoleTemplate;

    /** Clone any template into a new editable custom template, owned by $companyId (D11). */
    public function clone(RoleTemplate $template, string $newKey, string $companyId, ?string $newName = null): RoleTemplate;

    /**
     * Move a used or unused custom template to ARCHIVED (D13's preferred lifecycle path).
     * Refuses system templates, exactly like update()/delete().
     */
    public function archive(RoleTemplate $template, ?string $changeNote = null): RoleTemplate;

    /**
     * Delete a template. Refuses system templates AND refuses a template isInUse() reports
     * as currently held by any user (D13 hard security contract) — call archive() instead.
     *
     * @throws \Modules\IAM\Domain\Exceptions\RoleTemplateInUseException when isInUse() is true
     */
    public function delete(RoleTemplate $template): void;
}
