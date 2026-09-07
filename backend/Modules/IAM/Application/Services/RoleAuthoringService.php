<?php

declare(strict_types=1);

namespace Modules\IAM\Application\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\IAM\Domain\Catalog\BusinessRoleCatalog;
use Modules\IAM\Domain\Contracts\RoleTemplateRepositoryInterface;
use Modules\IAM\Domain\Enums\RoleCategory;
use Modules\IAM\Domain\Enums\RoleTemplateStatus;
use Modules\IAM\Domain\Exceptions\RoleLifecycleException;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\RoleTemplate;
use Modules\IAM\Domain\Models\UserTemplateAssignment;

/**
 * Role lifecycle authoring — Create / Edit / Clone / Archive / Restore / Delete, and the
 * editable permission matrix's save path
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §11 + §14).
 *
 * WHY THIS IS NOT A SECOND ROLE ENGINE
 * ------------------------------------
 * `RoleController` was read-only by architecture decision, and its docblock states the
 * reason precisely: writing `role_permissions` directly would be "a second, parallel
 * role-mutation path" outside `RoleTemplateCompiler`'s fail-closed validation. That
 * decision is respected here in full. This service NEVER writes `role_permissions`, never
 * touches the `permissions` table, and never calls `PermissionRegistry::sync()`. Every
 * grant change it performs goes through exactly one door:
 *
 *     RoleTemplateRepository::update(definition)  →  RoleTemplateCompiler::compile()
 *
 * which is the same door `UserRoleAssignmentService` and `RoleTemplateController` already
 * use. The compiler keeps its unknown-token rejection, its empty-wildcard rejection, its
 * three-cache invalidation for every current holder, and its audit entry.
 *
 * What this service adds is the missing INVERSE mapping: the administrator thinks in
 * roles, so "edit this role's permissions" must be expressible against a role. For a role
 * that already came from a template that is a lookup. For a legacy role that predates the
 * template system (`super-admin`, `viewer`, `sales`, `purchasing`, the `smoke-buyer-*`
 * rows, …) there was no template at all, and that is what `adoptIntoTemplate()` solves:
 * it mints a custom template whose definition MIRRORS the role's current grants exactly,
 * links it to the existing role, and from that moment the role is editable through the one
 * canonical path. Adoption is grant-preserving by construction — it reads the role's own
 * `role_permissions` rows and writes them back as the definition, so compiling immediately
 * afterwards is a no-op on effective access (§19: never silently increase access).
 */
class RoleAuthoringService
{
    public function __construct(
        private readonly RoleTemplateRepositoryInterface $templates,
        private readonly RoleTemplateCompiler $compiler,
        private readonly RoleTemplateAuditService $audit,
    ) {}

    /**
     * Create a brand-new business role.
     *
     * @param  array{name:string,description?:?string,category?:?string,permissions?:list<string>}  $data
     */
    public function create(array $data, string $companyId, ?int $actorId = null): Role
    {
        $name = trim((string) $data['name']);
        $key = $this->uniqueKey($name);

        if (Role::query()->where('slug', 'tpl-'.$key)->exists()) {
            throw RoleLifecycleException::slugTaken('tpl-'.$key);
        }

        $template = $this->templates->createCustom([
            'key' => $key,
            'name' => $name,
            'description' => $data['description'] ?? null,
            'category' => $this->category($data['category'] ?? null),
            'definition' => $this->definitionWithPermissions([], $data['permissions'] ?? []),
            'created_by' => $actorId,
            'company_id' => $companyId,
        ]);

        // A DRAFT template is not assignable (RoleTemplateStatus::isAssignable()). A role an
        // administrator just created is meant to be usable, so publish it through the
        // canonical status lifecycle rather than writing `status` behind the repository.
        $template = $this->publish($template, $actorId);

        $role = $this->compiler->compile($template);

        $this->audit->logRole('created', $role, ['template' => $template->key, 'actor_id' => $actorId], [], [
            'name' => $role->name,
            'slug' => $role->slug,
            'permission_count' => count($data['permissions'] ?? []),
        ]);

        return $role->refresh();
    }

    /**
     * Rename / re-describe a role. Metadata only — grants are untouched.
     *
     * @param  array{name?:string,description?:?string,category?:?string}  $data
     */
    public function updateMetadata(Role $role, array $data, ?int $actorId = null): Role
    {
        $template = $this->editableTemplate($role);

        $old = ['name' => $role->name, 'description' => $role->description];

        $this->templates->update($template, array_filter([
            'name' => $data['name'] ?? null,
            'description' => array_key_exists('description', $data) ? $data['description'] : null,
            'category' => isset($data['category']) ? $this->category($data['category']) : null,
            'updated_by' => $actorId,
        ], static fn ($v): bool => $v !== null), 'role metadata updated');

        // The runtime role carries its own display name — keep the two in step. This is the
        // role's own row, not `role_permissions`: no grant is written here.
        if (isset($data['name'])) {
            $role->name = trim((string) $data['name']);
        }
        if (array_key_exists('description', $data)) {
            $role->description = $data['description'];
        }
        $role->save();

        $this->audit->logRole('metadata_updated', $role, ['actor_id' => $actorId], $old, [
            'name' => $role->name,
            'description' => $role->description,
        ]);

        return $role->refresh();
    }

    /**
     * Save this role's per-item navigation VISIBILITY overrides (User-review remediation,
     * Batch 02, item I). UX policy only — see the migration/model docblocks. Deliberately
     * NOT routed through `editableTemplate()`/the compiler: this never touches a grant, a
     * Role Template, or `role_permissions`, so none of that machinery applies. A system
     * role is refused for the same reason `updateMetadata()` refuses one — an administrator
     * customizing `super-admin`'s own menu would be surprising and is not what any part of
     * this task asked for.
     *
     * @param  array<string,string>  $overrides  nav item key => 'visible' | 'hidden'
     */
    public function updateNavigationOverrides(Role $role, array $overrides, ?int $actorId = null): Role
    {
        if ($role->is_system) {
            throw RoleLifecycleException::systemRoleImmutable((string) $role->slug);
        }

        $old = $role->navigation_overrides;
        $role->navigation_overrides = $overrides === [] ? null : $overrides;
        $role->save();

        $this->audit->logRole('navigation_overrides_updated', $role, ['actor_id' => $actorId], [
            'navigation_overrides' => $old,
        ], [
            'navigation_overrides' => $role->navigation_overrides,
        ]);

        return $role->refresh();
    }

    /**
     * Save the editable permission matrix (§14).
     *
     * The submitted list is the COMPLETE desired grant set — the matrix is a full picture,
     * not a delta — so it replaces `definition.permissions` wholesale and the compiler
     * `sync()`s the role's grants to match. Unknown tokens are rejected by the compiler
     * before anything is written.
     *
     * @param  list<string>  $permissions  canonical permission names
     */
    public function updatePermissions(Role $role, array $permissions, ?int $actorId = null): Role
    {
        $template = $this->editableTemplate($role);

        $before = $role->permissions()->pluck('name')->sort()->values()->all();

        $this->templates->update($template, [
            'definition' => $this->definitionWithPermissions($template->definition ?? [], $permissions),
            'updated_by' => $actorId,
        ], 'role permission matrix updated');

        $role = $this->compiler->compile($template->refresh());

        $after = $role->permissions()->pluck('name')->sort()->values()->all();

        $this->audit->logRole(
            'permissions_updated',
            $role,
            [
                'actor_id' => $actorId,
                'template' => $template->key,
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ],
            ['permissions' => $before],
            ['permissions' => $after],
        );

        return $role->refresh();
    }

    /**
     * Clone a role into a new editable one. This is the sanctioned way to "edit" a
     * system-template-backed role: the source is never modified.
     */
    public function cloneRole(Role $role, string $newName, string $companyId, ?int $actorId = null): Role
    {
        $sourceTemplate = $role->roleTemplate;
        $definition = $sourceTemplate !== null
            ? ($sourceTemplate->definition ?? [])
            : $this->definitionWithPermissions([], $role->permissions()->pluck('name')->values()->all());

        $key = $this->uniqueKey($newName);

        $clone = $this->templates->createCustom([
            'key' => $key,
            'name' => trim($newName),
            'description' => $role->description,
            'category' => $sourceTemplate?->category ?? RoleCategory::CUSTOM->value,
            'definition' => $definition,
            'created_by' => $actorId,
            'company_id' => $companyId,
        ]);

        $clone = $this->publish($clone, $actorId);
        $cloned = $this->compiler->compile($clone);

        $this->audit->logRole('cloned', $cloned, [
            'actor_id' => $actorId,
            'cloned_from_role' => $role->slug,
            'cloned_from_template' => $sourceTemplate?->key,
        ], [], ['slug' => $cloned->slug, 'name' => $cloned->name]);

        return $cloned->refresh();
    }

    /**
     * Archive a role — withdraw it from the assignable catalogue.
     *
     * Deliberately NON-revoking: users who currently hold the role keep it and keep
     * resolving their permissions through it. Archiving is the safe alternative to
     * deletion that §11/§12 require, and quietly stripping access from live users would be
     * the mirror image of §19's "never silently increase access".
     */
    public function archive(Role $role, ?string $reason = null, ?int $actorId = null): Role
    {
        $this->assertNotSystem($role);

        if ($role->isArchived()) {
            throw RoleLifecycleException::alreadyArchived((string) $role->slug);
        }

        $userCount = $role->users()->count();

        DB::transaction(function () use ($role, $reason, $actorId): void {
            $role->archived_at = now();
            $role->archived_reason = $reason;
            $role->archived_by = $actorId;
            $role->save();

            // Mirror the state onto the backing template when it is one we may write to, so
            // the template library and the role catalogue cannot disagree. A system template
            // is left alone — it is immutable, and the ROLE archival above is what matters.
            $template = $role->roleTemplate;
            if ($template !== null && ! $template->is_system) {
                $this->templates->archive($template, 'archived with role '.$role->slug);
            }
        });

        $this->audit->logRole('archived', $role, [
            'actor_id' => $actorId,
            'reason' => $reason,
            'users_still_holding' => $userCount,
        ], ['archived_at' => null], ['archived_at' => $role->archived_at?->toIso8601String()]);

        return $role->refresh();
    }

    public function restore(Role $role, ?int $actorId = null): Role
    {
        if (! $role->isArchived()) {
            throw RoleLifecycleException::notArchived((string) $role->slug);
        }

        DB::transaction(function () use ($role, $actorId): void {
            $role->archived_at = null;
            $role->archived_reason = null;
            $role->archived_by = null;
            $role->save();

            $template = $role->roleTemplate;
            if ($template !== null && ! $template->is_system) {
                $this->publish($template, $actorId);
            }
        });

        $this->audit->logRole('restored', $role, ['actor_id' => $actorId]);

        return $role->refresh();
    }

    /**
     * Hard-delete a role — only when genuinely safe.
     *
     * §11's three prohibitions, each checked before anything is written:
     *   • system-required  → is_system, or a member of the protected catalogue
     *   • still has assigned users → user_roles count > 0
     *   • referenced by required configuration → backed by an immutable SYSTEM template,
     *     which is the platform's own "required configuration" for a role
     */
    public function delete(Role $role, ?int $actorId = null): void
    {
        $this->assertNotSystem($role);

        $userCount = $role->users()->count();
        if ($userCount > 0) {
            throw RoleLifecycleException::stillAssigned((string) $role->slug, $userCount);
        }

        $template = $role->roleTemplate;
        if ($template !== null && $template->is_system) {
            throw RoleLifecycleException::systemTemplateBacked((string) $role->slug, $template->key);
        }

        $slug = (string) $role->slug;
        $name = (string) $role->name;

        $this->audit->logRole('deleted', $role, ['actor_id' => $actorId], ['slug' => $slug, 'name' => $name], []);

        DB::transaction(function () use ($role, $template): void {
            // Grants are pivot rows owned by the role — detaching them is not a grant
            // MUTATION on a live role, it is the role ceasing to exist.
            $role->permissions()->detach();

            if ($template !== null) {
                // Unlink first so RoleTemplateRepository::delete()'s own zero-assignment
                // guard is the only thing standing between us and the row.
                DB::table('role_templates')->where('id', $template->id)->update(['role_id' => null]);

                if (! UserTemplateAssignment::where('role_template_id', $template->id)->exists()) {
                    $this->templates->delete($template->refresh());
                }
            }

            $role->delete();
        });
    }

    /**
     * Guarantee the role has an editable, non-system template behind it, minting a
     * grant-preserving one for a legacy role that has none.
     *
     * Called by editableTemplate(); exposed because the rationalization migration needs
     * the same adoption for legacy roles it maps forward.
     */
    public function adoptIntoTemplate(Role $role, ?string $companyId = null, ?int $actorId = null): RoleTemplate
    {
        $existing = $role->roleTemplate;
        if ($existing !== null) {
            return $existing;
        }

        $current = $role->permissions()->pluck('name')->values()->all();

        $template = $this->templates->createCustom([
            'key' => $this->uniqueKey($role->name.' role'),
            'name' => (string) $role->name,
            'description' => $role->description ?? ('Adopted from the pre-template role '.$role->slug),
            'category' => RoleCategory::CUSTOM->value,
            // MIRRORS the role's present grants — adoption changes nothing effective.
            'definition' => $this->definitionWithPermissions([], $current),
            'created_by' => $actorId,
            'company_id' => $companyId,
        ]);

        $template = $this->publish($template, $actorId);

        // Link the template to the EXISTING role rather than letting the compiler create a
        // `tpl-*` twin. Written through the same guarded raw update the compiler itself uses
        // for role_id (RoleTemplateCompiler::resolveRole()).
        DB::table('role_templates')->where('id', $template->id)->update(['role_id' => $role->getKey()]);
        $template->setAttribute('role_id', $role->getKey());

        $this->audit->logRole('adopted_into_template', $role, [
            'actor_id' => $actorId,
            'template' => $template->key,
            'mirrored_permission_count' => count($current),
        ]);

        return $template->refresh();
    }

    /** Publish through the canonical status lifecycle (RoleTemplateStatus). */
    public function publish(RoleTemplate $template, ?int $actorId = null): RoleTemplate
    {
        if ($template->status === RoleTemplateStatus::PUBLISHED->value) {
            return $template;
        }

        return $this->templates->update($template, [
            'status' => RoleTemplateStatus::PUBLISHED->value,
            'updated_by' => $actorId,
        ], 'template published');
    }

    public function unpublish(RoleTemplate $template, ?int $actorId = null): RoleTemplate
    {
        if ($template->status === RoleTemplateStatus::DRAFT->value) {
            return $template;
        }

        return $this->templates->update($template, [
            'status' => RoleTemplateStatus::DRAFT->value,
            'updated_by' => $actorId,
        ], 'template unpublished');
    }

    /**
     * The template a write must go through, or a refusal explaining what to do instead.
     */
    private function editableTemplate(Role $role): RoleTemplate
    {
        $this->assertNotSystem($role);

        $template = $role->roleTemplate;

        if ($template === null) {
            return $this->adoptIntoTemplate($role, null, null);
        }

        if ($template->is_system) {
            throw RoleLifecycleException::systemTemplateBacked((string) $role->slug, $template->key);
        }

        return $template;
    }

    private function assertNotSystem(Role $role): void
    {
        if ($role->is_system || in_array((string) $role->slug, BusinessRoleCatalog::SYSTEM_PROTECTED_ROLES, true)) {
            throw RoleLifecycleException::systemRoleImmutable((string) $role->slug);
        }
    }

    /**
     * Replace `permissions` inside an existing definition, preserving every other declared
     * facet (navigation, scopes, visibility, policies, landing page, dashboard). The
     * permission matrix must never be able to silently wipe a role's navigation whitelist.
     *
     * @param  array<string,mixed>  $definition
     * @param  list<string>  $permissions
     * @return array<string,mixed>
     */
    private function definitionWithPermissions(array $definition, array $permissions): array
    {
        $definition['permissions'] = array_values(array_unique(array_map(
            static fn ($p): string => trim((string) $p),
            $permissions,
        )));

        return $definition;
    }

    private function category(?string $category): string
    {
        if ($category === null) {
            return RoleCategory::CUSTOM->value;
        }

        return RoleCategory::tryFrom($category)?->value ?? RoleCategory::CUSTOM->value;
    }

    /** A stable, readable, unique template key derived from the role's display name. */
    private function uniqueKey(string $name): string
    {
        $base = Str::slug($name) !== '' ? Str::slug($name) : 'role';
        $key = $base;
        $suffix = 2;

        while (RoleTemplate::query()->where('key', $key)->exists()) {
            $key = $base.'-'.$suffix;
            $suffix++;
        }

        return $key;
    }
}
