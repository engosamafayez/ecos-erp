<?php

declare(strict_types=1);

namespace Tests\Unit\IAM;

use Modules\IAM\Domain\Catalog\RoleTemplateCatalog;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003 — Group A/B/C template reconciliation, extended by
 * TASK-ECOS-IAM-CLOSURE-INTEGRATION-GATE-004 — final catalog audit (§3, §5).
 *
 * Pure catalog-data assertions (RoleTemplateCatalog::all() has no DB dependency — the same
 * convention as PermissionNameTest). Every token asserted present here was independently
 * verified against live enforcement (route middleware, Policy::hasPermissionTo() calls) or a
 * dedicated permission-seeding migration before being written into the catalog — see the
 * inline evidence comment on each template in RoleTemplateCatalog.php and §14/§29 of
 * docs/verification/TASK-ECOS-IAM-ADMINISTRATION-WORKSPACE-003-REPORT.md, and §16/§22 of
 * docs/verification/TASK-ECOS-IAM-CLOSURE-INTEGRATION-GATE-004-REPORT.md, for the full trail —
 * including two self-corrections: an earlier pass fabricated several tokens for `cashier` and
 * `ai-analyst` (Task 3), and a whole-catalog audit (Task 4) found `sales.customers` (invalid,
 * real resource is `crm.customers`) and `accounting.ledgers.view` (a fabricated duplicate of
 * the already-held `finance.gl.view`) surviving in `sales-manager`/`sales-representative` and
 * `accountant` respectively. This test's assertions guard the corrected, verified state.
 *
 * Group B Template Validity Remediation (CTO-review continuation of Task 4): the initial
 * closure pass classified Group B (`hr-officer`, `customer-service-agent`) as
 * "RECONCILED — LEAVE AS-IS" and left them byte-for-byte unchanged, including 4 tokens
 * (`hr.employees.{create,update}`, `crm.tickets.{create,update}`) proven not to exist anywhere
 * — a current catalogue-validity defect (either template would throw
 * `UnknownTemplatePermissionException` on compile), not a deferred business question. The CTO's
 * least-privilege ruling itself is unchanged: the four tokens are removed outright, never
 * substituted with the broader real alternatives (`hr.employees.manage`, `crm.service.manage`).
 */
class RoleTemplateCatalogReconciliationTest extends TestCase
{
    private function definitionFor(string $key): array
    {
        foreach (RoleTemplateCatalog::all() as $template) {
            if ($template['key'] === $key) {
                return $template['definition'];
            }
        }

        throw new \RuntimeException("No catalog template with key '{$key}'");
    }

    private function permissionsFor(string $key): array
    {
        return $this->definitionFor($key)['permissions'];
    }

    // ── 27: eligible Group C tokens appear correctly ────────────────────────────

    public function test_group_c_eligible_tokens_appear_correctly(): void
    {
        // operations.preparation.{view,create,update} — real (routes/api.php middleware).
        $warehouseClerk = $this->permissionsFor('warehouse-clerk');
        $this->assertContains('operations.preparation.view', $warehouseClerk);
        $this->assertContains('operations.preparation.create', $warehouseClerk);
        $this->assertContains('operations.preparation.update', $warehouseClerk);

        // purchasing.purchases.{review,select_supplier} — real (config/permissions.php
        // modules.purchasing.purchases action list; corroborated by the legacy
        // 'purchasing-officer' role_permissions grant, same two actions).
        $purchasingOfficer = $this->permissionsFor('purchasing-officer');
        $this->assertContains('purchasing.purchases.review', $purchasingOfficer);
        $this->assertContains('purchasing.purchases.select_supplier', $purchasingOfficer);

        // dispatch.{monitoring.view,audit.view,queue.manage,session.manage} — real
        // (Logistics/Dispatch seed_phase3_permissions migration, exact string match).
        $dispatcher = $this->permissionsFor('dispatcher');
        $this->assertContains('dispatch.monitoring.view', $dispatcher);
        $this->assertContains('dispatch.audit.view', $dispatcher);
        $this->assertContains('dispatch.queue.manage', $dispatcher);
        $this->assertContains('dispatch.session.manage', $dispatcher);

        // delivery.{analytics.view,pod.capture,cod.collect,return.manage} — real
        // (Logistics/Delivery seed_delivery_permissions migration, exact string match).
        // Scopes use the exact per-resource keys scopeFor() requires (EffectiveRoleProfile
        // does an exact lookup, not a prefix match) — a single 'delivery' => 'self' would
        // silently leave the driver unscoped.
        $driver = $this->definitionFor('driver');
        $driverPermissions = $driver['permissions'];
        $this->assertContains('delivery.analytics.view', $driverPermissions);
        $this->assertContains('delivery.pod.capture', $driverPermissions);
        $this->assertContains('delivery.cod.collect', $driverPermissions);
        $this->assertContains('delivery.return.manage', $driverPermissions);
        $this->assertSame(
            ['delivery.pod' => 'self', 'delivery.cod' => 'self', 'delivery.return' => 'self', 'delivery.analytics' => 'self'],
            $driver['scopes'],
        );

        // crm.sales.{view,manage,convert} — real (Crm/Sales seed_crm_sales_permissions_table
        // migration, exact string match).
        $salesRep = $this->permissionsFor('sales-representative');
        $this->assertContains('crm.sales.view', $salesRep);
        $this->assertContains('crm.sales.manage', $salesRep);
        $this->assertContains('crm.sales.convert', $salesRep);

        // pos.terminal.{view,operate} — real (config/permissions.php modules.pos; also the
        // sole permission gating the entire /pos route group in routes/api.php). This is the
        // corrected state — see test_group_c_previously_fabricated_tokens_are_not_present().
        $cashier = $this->definitionFor('cashier');
        $this->assertContains('pos.terminal.view', $cashier['permissions']);
        $this->assertContains('pos.terminal.operate', $cashier['permissions']);
        $this->assertSame(['pos.terminal' => 'self'], $cashier['scopes']);

        // bae.attribution.view / claude_bridge.platform.view / engineering.platform.view —
        // real (config/permissions.php modules.{bae,claude_bridge,engineering}). Corrected
        // state — see test_group_c_previously_fabricated_tokens_are_not_present().
        $aiAnalyst = $this->permissionsFor('ai-analyst');
        $this->assertContains('bae.attribution.view', $aiAnalyst);
        $this->assertContains('claude_bridge.platform.view', $aiAnalyst);
        $this->assertContains('engineering.platform.view', $aiAnalyst);
    }

    // ── 28: stale/invalid tokens were removed, and only with evidence ───────────

    public function test_group_c_stale_tokens_are_removed(): void
    {
        $this->assertNotContains('operations.preparation.operate', $this->permissionsFor('warehouse-clerk'));
        // Deliberately withheld, not just renamed — a floor clerk operates preparation
        // records, but does not delete them (see the catalog's own inline note).
        $this->assertNotContains('operations.preparation.delete', $this->permissionsFor('warehouse-clerk'));

        $this->assertNotContains('purchasing.purchases.update', $this->permissionsFor('purchasing-officer'));
        // manager-level actions on the same resource, deliberately withheld from the officer.
        foreach (['approve', 'execute', 'merge', 'split'] as $managerAction) {
            $this->assertNotContains("purchasing.purchases.{$managerAction}", $this->permissionsFor('purchasing-officer'));
        }

        $dispatcher = $this->permissionsFor('dispatcher');
        $this->assertNotContains('logistics.dispatch.view', $dispatcher);
        $this->assertNotContains('logistics.dispatch.operate', $dispatcher);

        $driver = $this->permissionsFor('driver');
        $this->assertNotContains('logistics.deliveries.view', $driver);
        $this->assertNotContains('logistics.deliveries.operate', $driver);

        $this->assertNotContains('crm.leads.create', $this->permissionsFor('sales-representative'));

        $this->assertNotContains('pos.sessions.view', $this->permissionsFor('cashier'));
        $this->assertNotContains('pos.sessions.operate', $this->permissionsFor('cashier'));
        $this->assertNotContains('pos.sales.create', $this->permissionsFor('cashier'));

        foreach (['bae.view', 'claude_bridge.view', 'engineering.view'] as $stale) {
            $this->assertNotContains($stale, $this->permissionsFor('ai-analyst'));
        }
    }

    /**
     * A self-correction guard: an earlier pass at this same reconciliation (within this task,
     * before commit) invented plausible-looking but nonexistent tokens for `cashier` and
     * `ai-analyst`, and even cited a migration as evidence that on inspection does not contain
     * them. Caught and fixed before commit — this test pins the fix so it cannot silently
     * regress back to the fabricated set.
     */
    public function test_group_c_previously_fabricated_tokens_are_not_present(): void
    {
        $cashier = $this->permissionsFor('cashier');
        foreach (['pos.shifts.view', 'pos.shifts.open_shift', 'pos.carts.create', 'pos.carts.checkout', 'pos.payments.create'] as $fabricated) {
            $this->assertNotContains($fabricated, $cashier, "'{$fabricated}' does not exist anywhere in the codebase and must not be granted.");
        }

        $aiAnalyst = $this->permissionsFor('ai-analyst');
        $fabricatedAiTokens = [
            'bae.attributions.view', 'bae.timeline.view',
            'claude_bridge.settings.view', 'claude_bridge.tasks.view', 'claude_bridge.workers.view',
            'engineering.pipelines.view', 'engineering.tasks.view', 'engineering.queue.view',
            'engineering.releases.view', 'engineering.repair.view', 'engineering.workers.view', 'engineering.ai_reviews.view',
        ];
        foreach ($fabricatedAiTokens as $fabricated) {
            $this->assertNotContains($fabricated, $aiAnalyst, "'{$fabricated}' does not exist anywhere in the codebase and must not be granted.");
        }
    }

    // ── 29: Group A remains dependency-blocked — untouched, no fake permissions ─

    public function test_group_a_templates_remain_unchanged_with_no_fake_permissions(): void
    {
        // Group A (TASK-IAM-TEMPLATE-RECONCILIATION-001): manufacturing/shipping/packing/
        // logistics.transfers domains genuinely do not exist as granular tokens yet — these 8
        // templates must keep their original broad/wildcard permissions verbatim. Task 3 must
        // not invent the missing domains, and must not narrow or otherwise alter these entries.
        $groupA = [
            'warehouse-manager' => ['inventory.*', 'operations.*', 'logistics.transfers.*'],
            'shipping-manager' => ['logistics.*', 'shipping.*'],
            'production-director' => ['manufacturing.*', 'inventory.*', 'operations.*'],
            'production-manager' => ['manufacturing.*', 'inventory.recipes.*', 'operations.*'],
            'production-operator' => ['manufacturing.workorders.view', 'manufacturing.workorders.operate', 'inventory.recipes.view'],
            'quality-inspector' => ['manufacturing.quality.view', 'manufacturing.quality.operate', 'inventory.products.view'],
            'packaging-supervisor' => ['operations.packing.view', 'operations.packing.operate', 'operations.packing.manage'],
            'packaging-operator' => ['operations.packing.view', 'operations.packing.operate'],
        ];

        foreach ($groupA as $key => $expectedPermissions) {
            $this->assertSame($expectedPermissions, $this->permissionsFor($key), "Group A template '{$key}' must remain exactly as it was — dependency-blocked, not reconciled.");
        }

        // No template anywhere in the catalog may reference the specific granular actions
        // Group A's missing domains would need (manufacturing.production.*, shipping.dispatch.*,
        // operations.packing.<anything beyond view/operate/manage>, logistics.transfers.<action>)
        // — those permissions were never created, so no template may claim to hold them.
        $forbidden = [
            'manufacturing.production.create', 'manufacturing.production.update',
            'shipping.dispatch.view', 'shipping.dispatch.operate',
            'logistics.transfers.view', 'logistics.transfers.create', 'logistics.transfers.approve',
        ];
        foreach (RoleTemplateCatalog::all() as $template) {
            foreach ($forbidden as $fake) {
                $this->assertNotContains(
                    $fake,
                    $template['definition']['permissions'],
                    "Template '{$template['key']}' must not hold '{$fake}' — this permission does not exist; Group A stays dependency-blocked.",
                );
            }
        }
    }

    // ── TASK-ECOS-IAM-CLOSURE-INTEGRATION-GATE-004 §3: sales.customers → crm.customers ──

    /**
     * 'sales.customers' is not a real resource anywhere in the codebase — the real customer
     * authority is crm.customers.{view,create,update,delete,merge,archive} (config/
     * permissions.php base registry + Modules/Crm/Customers' dedicated seeder, and what every
     * customer route in routes/api.php actually checks). sales-manager already held crm.* (so
     * its fix is scope-key-only); sales-representative held literal, non-functional
     * 'sales.customers.{view,create}' tokens and is fixed to the exact same two action levels
     * under the real resource name — no widening to update/delete/merge/archive.
     */
    public function test_sales_customers_stale_reference_is_reconciled_to_crm_customers(): void
    {
        $salesManager = $this->definitionFor('sales-manager');
        $this->assertNotContains('sales.customers', array_keys($salesManager['scopes']), "sales-manager must not carry the dead 'sales.customers' scope key.");
        $this->assertArrayHasKey('crm.customers', $salesManager['scopes']);
        $this->assertSame('team', $salesManager['scopes']['crm.customers']);
        // Permission-side is unaffected: crm.customers.* was already covered by the crm.*
        // wildcard this role holds — confirm that wildcard is still present, untouched.
        $this->assertContains('crm.*', $salesManager['permissions']);

        $salesRep = $this->definitionFor('sales-representative');
        $this->assertNotContains('sales.customers.view', $salesRep['permissions']);
        $this->assertNotContains('sales.customers.create', $salesRep['permissions']);
        $this->assertContains('crm.customers.view', $salesRep['permissions']);
        $this->assertContains('crm.customers.create', $salesRep['permissions']);
        // No widening: the two stale tokens were view/create-level only, never held
        // update/delete/merge/archive, and must not gain them through this fix.
        foreach (['crm.customers.update', 'crm.customers.delete', 'crm.customers.merge', 'crm.customers.archive'] as $wider) {
            $this->assertNotContains($wider, $salesRep['permissions'], "sales-representative must not gain '{$wider}' — the stale tokens never implied it.");
        }
        $this->assertNotContains('sales.customers', array_keys($salesRep['scopes']));
        $this->assertArrayHasKey('crm.customers', $salesRep['scopes']);
        $this->assertSame('self', $salesRep['scopes']['crm.customers']);
    }

    // ── TASK-ECOS-IAM-CLOSURE-INTEGRATION-GATE-004 §5: accounting.ledgers.view removed ──

    /**
     * BUG-GL-011 (see UnknownTemplatePermissionException's own docblock): "Four finance
     * templates shipped that way against an accounting.* namespace that has zero seeded
     * permissions." This was the one that survived. 'accounting.ledgers.view' was a pure
     * redundant duplicate of 'finance.gl.view' (already held on the same line, seeded with the
     * description "View the general ledger and journals") — removing it does not reduce
     * effective privilege, and leaving it in would throw UnknownTemplatePermissionException the
     * moment this template was ever compiled/assigned.
     */
    public function test_accountant_fabricated_accounting_ledgers_token_is_removed(): void
    {
        $accountant = $this->permissionsFor('accountant');
        $this->assertNotContains('accounting.ledgers.view', $accountant);
        $this->assertContains('finance.gl.view', $accountant, 'finance.gl.view already covers the intended "view the ledger" capability.');
    }

    // ── TASK-ECOS-IAM-CLOSURE-INTEGRATION-GATE-004 §1/§19, then CTO-review continuation ──

    /**
     * CTO review correction (Group B Template Validity Remediation): the initial Task 4 pass
     * classified Group B as "RECONCILED — LEAVE AS-IS" and left `hr-officer`/
     * `customer-service-agent` byte-for-byte unchanged, including 4 tokens
     * (`hr.employees.{create,update}`, `crm.tickets.{create,update}`) proven not to exist
     * anywhere in the codebase. The CTO correctly identified that this is a *current
     * catalogue-validity defect* — RoleTemplateCompiler::compile() would throw
     * UnknownTemplatePermissionException the moment either template was ever assigned — not a
     * deferred business question, and required remediation. The least-privilege ruling itself
     * is unchanged: the fix is to REMOVE the four invalid tokens outright, never to substitute
     * the broader real alternatives (`hr.employees.manage`, `crm.service.manage`) merely to
     * make the templates compile. See the Task 4 report's Group B section for the full
     * evidence trail this file mirrors.
     */

    /**
     * Every real permission token in the namespaces Group B templates reference — evidence-
     * sourced the same way as every other assertion in this file (config/permissions.php's base
     * registry + the relevant dedicated seeding migrations). Used as a static proxy for "would
     * pass RoleTemplateCompiler::compile()'s own catalogue check" — this device has no PHP/DB
     * toolchain to run the live compiler (Task 4 report, tests-executed section), so this
     * reproduces its exact unknown-token diff against the same sources cited throughout this
     * file, not a guess.
     */
    private function canonicalGroupBNamespaceTokens(): array
    {
        return [
            // hr.employees — Modules/Hr/Workforce/…/seed_hr_workforce_permissions_table.php;
            // also enforced directly by routes/api.php permission: middleware.
            'hr.employees.view', 'hr.employees.manage',
            // hr.attendance — Modules/Hr/Attendance/…/seed_hr_attendance_permissions_table.php
            'hr.attendance.view', 'hr.attendance.register',
            // hr.leave — held unchanged by hr-officer throughout this whole batch
            'hr.leave.view',
            // crm.service — Modules/Crm/Service/…/seed_crm_service_permissions_table.php
            'crm.service.view', 'crm.service.manage', 'crm.service.assign', 'crm.service.resolve', 'crm.service.admin',
            // omnichannel.inbox — config/permissions.php modules.omnichannel
            'omnichannel.inbox.view', 'omnichannel.inbox.manage',
            // crm.customers — config/permissions.php modules.crm + Crm/Customers seeder
            'crm.customers.view', 'crm.customers.create', 'crm.customers.update', 'crm.customers.delete', 'crm.customers.merge', 'crm.customers.archive',
        ];
    }

    // ── §6.1/6.2 + the required Group-B catalogue invariant: no nonexistent token, at all,
    //    for any token either template holds — not just a re-check of the four known ones ──

    public function test_group_b_templates_contain_no_nonexistent_permission_token(): void
    {
        $canonical = $this->canonicalGroupBNamespaceTokens();
        foreach (['hr-officer', 'customer-service-agent'] as $key) {
            foreach ($this->permissionsFor($key) as $token) {
                $this->assertContains($token, $canonical, "Template '{$key}' holds '{$token}', which does not resolve to a canonical permission definition.");
            }
        }
    }

    // ── §6.3/6.4: both templates compile against the canonical catalogue ───────

    public function test_group_b_templates_compile_against_the_canonical_catalogue(): void
    {
        // Reproduces RoleTemplateCompiler::compile()'s own validation (Permission::query()
        // ->whereIn('name', $names) then array_diff for $unknown) as a pure, DB-less check.
        $canonical = array_flip($this->canonicalGroupBNamespaceTokens());
        foreach (['hr-officer', 'customer-service-agent'] as $key) {
            $unknown = array_values(array_filter(
                $this->permissionsFor($key),
                static fn (string $t): bool => ! isset($canonical[$t]),
            ));
            $this->assertSame(
                [],
                $unknown,
                "Template '{$key}' would throw UnknownTemplatePermissionException on compile — unresolved: ".implode(', ', $unknown),
            );
        }
    }

    // ── §6.5: neither template receives the broader manage-level permission ────

    public function test_group_b_templates_do_not_receive_manage_level_widening(): void
    {
        $this->assertNotContains('hr.employees.manage', $this->permissionsFor('hr-officer'));
        $this->assertNotContains('crm.service.manage', $this->permissionsFor('customer-service-agent'));
    }

    // ── §6.6: effective privileges did not increase vs. the pre-remediation valid subset ──

    public function test_group_b_effective_privileges_did_not_increase(): void
    {
        // The valid subset of the pre-remediation definition — i.e. excluding the two invalid
        // (hence non-functional either way) tokens each template held. This is exactly what
        // each role could ever actually have done; remediation must be a subset of this, never
        // a superset, and (confirmed by the second assertion) not a stricter subset either —
        // nothing that was already valid was accidentally dropped.
        $hrOfficerPreRemediationValidSubset = ['hr.employees.view', 'hr.attendance.view', 'hr.attendance.register', 'hr.leave.view'];
        $csAgentPreRemediationValidSubset = ['crm.service.view', 'omnichannel.inbox.view', 'omnichannel.inbox.manage', 'crm.customers.view'];

        $this->assertEmpty(
            array_diff($this->permissionsFor('hr-officer'), $hrOfficerPreRemediationValidSubset),
            'hr-officer must not hold any permission beyond its pre-remediation valid subset.',
        );
        $this->assertEmpty(
            array_diff($this->permissionsFor('customer-service-agent'), $csAgentPreRemediationValidSubset),
            'customer-service-agent must not hold any permission beyond its pre-remediation valid subset.',
        );
        $this->assertSame($hrOfficerPreRemediationValidSubset, $this->permissionsFor('hr-officer'));
        $this->assertSame($csAgentPreRemediationValidSubset, $this->permissionsFor('customer-service-agent'));
    }

    // ── §6.7: the four removed tokens cannot reappear unnoticed, anywhere in the catalog ──

    public function test_group_b_removed_tokens_cannot_reappear_unnoticed(): void
    {
        $removedTokens = ['hr.employees.create', 'hr.employees.update', 'crm.tickets.create', 'crm.tickets.update'];

        foreach (RoleTemplateCatalog::all() as $template) {
            foreach ($removedTokens as $token) {
                $this->assertNotContains(
                    $token,
                    $template['definition']['permissions'],
                    "Template '{$template['key']}' must not hold '{$token}' — proven nonexistent, removed as part of Group B Template Validity Remediation, and must not silently reappear anywhere in the catalog.",
                );
            }
        }
    }
}
