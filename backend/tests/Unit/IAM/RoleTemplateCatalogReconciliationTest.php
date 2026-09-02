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

    // ── TASK-ECOS-IAM-CLOSURE-INTEGRATION-GATE-004 §1/§19: Group B — CTO ruling ─────────

    /**
     * CTO final ruling (this task's §1): hr-officer and customer-service-agent are RECONCILED —
     * LEAVE AS-IS. No privilege expansion, even where a broken token could technically be
     * "corrected" to a real one — the CTO explicitly disallowed that here ("Do NOT add optional
     * permissions merely because those permissions exist in the catalogue. Any future expansion
     * ... requires an explicit business requirement"). This test pins the exact byte-for-byte
     * permission lists so a future pass cannot silently widen either role while "fixing" them.
     */
    public function test_group_b_templates_are_untouched_per_cto_least_privilege_ruling(): void
    {
        $this->assertSame(
            ['hr.employees.view', 'hr.employees.create', 'hr.employees.update', 'hr.attendance.view', 'hr.attendance.register', 'hr.leave.view'],
            $this->permissionsFor('hr-officer'),
        );
        $this->assertSame(
            ['crm.service.view', 'crm.tickets.create', 'crm.tickets.update', 'omnichannel.inbox.view', 'omnichannel.inbox.manage', 'crm.customers.view'],
            $this->permissionsFor('customer-service-agent'),
        );

        // Not widened to the real, broader equivalents that exist in the catalog today
        // (hr.employees.manage; crm.service.manage) — the CTO ruling forecloses this path for
        // this batch, even though both are individually "fixable" the same way sales.customers
        // and accounting.ledgers.view were.
        $this->assertNotContains('hr.employees.manage', $this->permissionsFor('hr-officer'));
        $this->assertNotContains('crm.service.manage', $this->permissionsFor('customer-service-agent'));
    }
}
