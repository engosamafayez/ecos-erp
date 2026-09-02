<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Catalog;

use Modules\IAM\Domain\Enums\RoleCategory;

/**
 * The 40 official ECOS Enterprise Role Templates (TASK-IAM-003 / ADR-039).
 *
 * Declarative job profiles referencing the REAL platform vocabulary:
 *  - permissions: catalog domains + wildcards (expanded at compile/preview time)
 *  - navigation: module ids from config/module-navigation.ts
 *  - dashboard.profile: a DashboardProfile preset key
 *  - landing_page: a ROUTES key
 *  - visibility.hidden_fields / scopes / policies: ADR-038 tokens
 *
 * These are seeded as immutable system templates. Change one by cloning it.
 */
final class RoleTemplateCatalog
{
    /**
     * Every navigable module id (full access).
     *
     * `executive` is the cross-domain executive board. It appears here and in no
     * other template's list, which is the gate: the four C-level templates below
     * are the only ones that carry it, because a navigation whitelist is
     * authoritative in the UI — a module absent from a template's list is
     * invisible to that role regardless of the permissions it holds.
     */
    private const NAV_ALL = [
        'dashboard', 'executive', 'commerce', 'shipping', 'pos', 'inventory', 'purchasing',
        'finance', 'crm', 'customerEngagement', 'omnichannel', 'manufacturing', 'operations',
        'marketing', 'core', 'logistics', 'hr', 'reports', 'administration', 'engineering',
    ];

    /** Cost/margin fields hidden from floor operations roles. */
    private const HIDE_COSTS = [
        'cost', 'average_cost', 'fifo_cost', 'profit', 'margin',
        'supplier_prices', 'recipe_cost', 'manufacturing_cost',
    ];

    /** Cost/purchase fields hidden from sales-facing roles. */
    private const HIDE_SALES = ['cost', 'profit', 'margin', 'purchase_prices', 'supplier_prices'];

    /**
     * @return list<array{key:string,name:string,category:string,description:string,definition:array<string,mixed>}>
     */
    public static function all(): array
    {
        $E = RoleCategory::EXECUTIVE->value;
        $M = RoleCategory::MANAGEMENT->value;
        $O = RoleCategory::OPERATIONS->value;
        $W = RoleCategory::WAREHOUSE->value;
        $MF = RoleCategory::MANUFACTURING->value;
        $S = RoleCategory::SALES->value;
        $CS = RoleCategory::CUSTOMER_SERVICE->value;
        $FIN = RoleCategory::FINANCE->value;
        $ACC = RoleCategory::ACCOUNTING->value;
        $HR = RoleCategory::HR->value;
        $MK = RoleCategory::MARKETING->value;
        $SH = RoleCategory::SHIPPING->value;
        $ADM = RoleCategory::ADMINISTRATION->value;
        $IT = RoleCategory::IT->value;
        $AI = RoleCategory::AI_PLATFORM->value;

        return [
            // ── Executive ────────────────────────────────────────────────────────
            self::make('ceo', 'CEO', $E, 'Chief Executive Officer — full visibility across the enterprise.', [
                'permissions' => ['*'], 'nav' => self::NAV_ALL, 'dashboard' => ['profile' => 'executive'],
                'landing' => 'dashboard',
            ]),
            self::make('coo', 'COO', $E, 'Chief Operating Officer — operations, fulfillment, manufacturing, logistics.', [
                'permissions' => ['*'], 'nav' => self::NAV_ALL, 'dashboard' => ['profile' => 'operations'],
                'landing' => 'dashboard',
            ]),
            self::make('cfo', 'CFO', $E, 'Chief Financial Officer — finance, accounting, full cost visibility.', [
                'permissions' => ['*'], 'nav' => self::NAV_ALL, 'dashboard' => ['profile' => 'finance'],
                'landing' => 'accounting',
            ]),
            self::make('cto', 'CTO', $E, 'Chief Technology Officer — engineering, AI platform, administration.', [
                'permissions' => ['*'], 'nav' => self::NAV_ALL, 'dashboard' => ['profile' => 'executive'],
                'landing' => 'engineeringDashboard',
            ]),

            // ── Directors (Management) ───────────────────────────────────────────
            self::make('operations-director', 'Operations Director', $M, 'Directs operations, preparation and fulfillment.', [
                'permissions' => ['operations.*', 'inventory.*', 'logistics.*', 'purchasing.*'],
                'nav' => ['dashboard', 'operations', 'inventory', 'logistics', 'shipping', 'purchasing', 'reports'],
                'dashboard' => ['profile' => 'operations'], 'landing' => 'waveWorkspace',
            ]),
            self::make('sales-director', 'Sales Director', $M, 'Directs sales, commerce and CRM.', [
                'permissions' => ['sales.*', 'crm.*', 'pos.*'],
                'nav' => ['dashboard', 'commerce', 'crm', 'pos', 'customerEngagement', 'reports'],
                'dashboard' => ['profile' => 'crm'], 'landing' => 'orders',
            ]),
            self::make('warehouse-director', 'Warehouse Director', $M, 'Directs all warehouses and inventory.', [
                'permissions' => ['inventory.*', 'operations.*', 'logistics.*'],
                'nav' => ['dashboard', 'inventory', 'operations', 'logistics', 'reports'],
                'dashboard' => ['profile' => 'warehouse'], 'landing' => 'inventoryDashboard',
            ]),
            self::make('finance-director', 'Finance Director', $M, 'Directs finance and accounting.', [
                // The three payment-proof verbs are listed EXPLICITLY, not reached by a
                // wildcard: they live under `sales.*`, which this template deliberately does not
                // hold. Finance reviews payment evidence without holding any order verb — the
                // same shape as the concrete `finance-manager` role, whose proof grant is exactly
                // view+verify+reject. `sales.orders.proof_upload` is excluded on purpose: upload
                // is a Sales capability, and keeping the two apart is the role-level half of the
                // maker-checker separation (the identity-level half lives in the actions).
                'permissions' => [
                    'finance.*', 'purchasing.supplier_invoices.*',
                    'sales.orders.proof_view', 'sales.orders.proof_verify', 'sales.orders.proof_reject',
                ],
                'nav' => ['dashboard', 'finance', 'purchasing', 'reports'],
                'dashboard' => ['profile' => 'finance'], 'landing' => 'accounting',
                'policies' => ['close-period', 'approve-journals'],
            ]),
            self::make('production-director', 'Production Director', $M, 'Directs manufacturing and production.', [
                'permissions' => ['manufacturing.*', 'inventory.*', 'operations.*'],
                'nav' => ['dashboard', 'manufacturing', 'inventory', 'operations', 'reports'],
                'dashboard' => ['profile' => 'manufacturing'], 'landing' => 'recipes',
            ]),
            self::make('marketing-director', 'Marketing Director', $M, 'Directs marketing and campaigns.', [
                'permissions' => ['marketing.*', 'bae.*', 'cep.*'],
                'nav' => ['dashboard', 'marketing', 'crm', 'reports'],
                'dashboard' => ['profile' => 'marketing'], 'landing' => 'marketing',
            ]),
            self::make('hr-director', 'HR Director', $M, 'Directs human resources.', [
                'permissions' => ['hr.*'], 'nav' => ['dashboard', 'hr', 'reports'],
                'dashboard' => ['profile' => 'executive'], 'landing' => 'hr',
            ]),
            self::make('customer-service-manager', 'Customer Service Manager', $CS, 'Manages the customer service team and SLAs.', [
                'permissions' => ['crm.*', 'omnichannel.*', 'cep.*'],
                'nav' => ['dashboard', 'crm', 'customerEngagement', 'omnichannel', 'reports'],
                'dashboard' => ['profile' => 'crm'], 'landing' => 'crm',
            ]),

            // ── Warehouse ────────────────────────────────────────────────────────
            self::make('warehouse-manager', 'Warehouse Manager', $W, 'Runs a warehouse — approvals, adjustments, transfers.', [
                'permissions' => ['inventory.*', 'operations.*', 'logistics.transfers.*'],
                'nav' => ['dashboard', 'inventory', 'operations', 'logistics'],
                'dashboard' => ['profile' => 'warehouse', 'hidden' => ['marketing-perf']],
                'landing' => 'inventoryDashboard', 'scopes' => ['inventory' => 'warehouse'],
                'policies' => ['inventory-approval', 'stock-adjustment', 'transfer-approval'],
            ]),
            self::make('warehouse-clerk', 'Warehouse Clerk', $W, 'Floor clerk — no cost visibility, no overrides.', [
                // TASK-IAM-TEMPLATE-RECONCILIATION-001 Group C: 'operations.preparation.operate'
                // did not exist — the domain's real actions are view/create/update/delete
                // (config/permissions.php). Resolved to create+update ("do the day-to-day
                // preparation work"); delete is deliberately withheld — a floor clerk operating
                // preparation records is not the same grant as being able to remove them, and
                // the original single token's intent was operational, not destructive.
                'permissions' => [
                    'inventory.products.view', 'inventory.stock.view', 'inventory.count.view',
                    'inventory.count.create', 'inventory.count.update', 'inventory.recipes.view',
                    'operations.preparation.view', 'operations.preparation.create', 'operations.preparation.update',
                ],
                'nav' => ['dashboard', 'inventory', 'operations'],
                'dashboard' => ['profile' => 'warehouse', 'hidden' => ['sales-revenue', 'marketing-perf', 'ai-intelligence']],
                'landing' => 'inventoryDashboard', 'hidden' => self::HIDE_COSTS,
                'scopes' => ['inventory' => 'warehouse'],
                'policies' => [],
            ]),
            self::make('inventory-controller', 'Inventory Controller', $W, 'Owns inventory accuracy and costing across warehouses.', [
                'permissions' => ['inventory.*'],
                'nav' => ['dashboard', 'inventory', 'reports'],
                'dashboard' => ['profile' => 'warehouse'], 'landing' => 'inventoryDashboard',
                'policies' => ['stock-adjustment'],
            ]),

            // ── Purchasing (Operations) ──────────────────────────────────────────
            self::make('purchasing-manager', 'Purchasing Manager', $O, 'Approves purchase orders and manages suppliers.', [
                'permissions' => ['purchasing.*'],
                'nav' => ['dashboard', 'purchasing', 'inventory', 'reports'],
                'dashboard' => ['profile' => 'operations'], 'landing' => 'procurementHub',
                'policies' => ['purchase-approval'],
            ]),
            self::make('purchasing-officer', 'Purchasing Officer', $O, 'Creates and manages purchase orders.', [
                // TASK-IAM-TEMPLATE-RECONCILIATION-001 Group C: 'purchasing.purchases.update'
                // did not exist — the domain has view/create/review/merge/split/select_supplier/
                // approve/execute/cancel/export, no bare "update". Resolved to review +
                // select_supplier: both are already the officer-level grant on the independent,
                // pre-existing 'purchasing-officer' legacy role (config/permissions.php
                // role_permissions), corroborating this is the right scope. approve/execute/
                // merge/split are deliberately withheld — that same legacy config grants them
                // only to 'purchasing-manager', a level above officer; adopting them here would
                // widen an officer to manager-level purchasing authority.
                'permissions' => [
                    'purchasing.purchases.view', 'purchasing.purchases.create',
                    'purchasing.purchases.review', 'purchasing.purchases.select_supplier',
                    'purchasing.materials.view', 'purchasing.suppliers.view',
                ],
                'nav' => ['dashboard', 'purchasing', 'inventory'],
                'dashboard' => ['profile' => 'operations'], 'landing' => 'procurementHub',
            ]),

            // ── Manufacturing ────────────────────────────────────────────────────
            self::make('production-manager', 'Production Manager', $MF, 'Runs the production floor and work orders.', [
                'permissions' => ['manufacturing.*', 'inventory.recipes.*', 'operations.*'],
                'nav' => ['dashboard', 'manufacturing', 'inventory', 'operations'],
                'dashboard' => ['profile' => 'manufacturing'], 'landing' => 'recipes',
                'policies' => ['production-approval'],
            ]),
            self::make('production-operator', 'Production Operator', $MF, 'Executes production tasks — no cost visibility.', [
                'permissions' => ['manufacturing.workorders.view', 'manufacturing.workorders.operate', 'inventory.recipes.view'],
                'nav' => ['dashboard', 'manufacturing'],
                'dashboard' => ['profile' => 'manufacturing', 'hidden' => ['sales-revenue', 'marketing-perf']],
                'landing' => 'recipes', 'hidden' => self::HIDE_COSTS,
            ]),
            self::make('quality-inspector', 'Quality Inspector', $MF, 'Inspects and signs off production quality.', [
                'permissions' => ['manufacturing.quality.view', 'manufacturing.quality.operate', 'inventory.products.view'],
                'nav' => ['dashboard', 'manufacturing', 'inventory'],
                'dashboard' => ['profile' => 'manufacturing'], 'landing' => 'recipes',
                'hidden' => self::HIDE_COSTS, 'policies' => ['quality-approval'],
            ]),
            self::make('packaging-supervisor', 'Packaging Supervisor', $MF, 'Supervises packaging operations.', [
                'permissions' => ['operations.packing.view', 'operations.packing.operate', 'operations.packing.manage'],
                'nav' => ['dashboard', 'operations', 'manufacturing'],
                'dashboard' => ['profile' => 'manufacturing'], 'landing' => 'waveWorkspace',
                'hidden' => self::HIDE_COSTS,
            ]),
            self::make('packaging-operator', 'Packaging Operator', $MF, 'Executes packaging tasks.', [
                'permissions' => ['operations.packing.view', 'operations.packing.operate'],
                'nav' => ['dashboard', 'operations'],
                'dashboard' => ['profile' => 'manufacturing', 'hidden' => ['sales-revenue', 'marketing-perf']],
                'landing' => 'waveWorkspace', 'hidden' => self::HIDE_COSTS,
            ]),

            // ── Shipping ─────────────────────────────────────────────────────────
            self::make('shipping-manager', 'Shipping Manager', $SH, 'Runs dispatch, carriers and delivery.', [
                'permissions' => ['logistics.*', 'shipping.*'],
                'nav' => ['dashboard', 'shipping', 'logistics', 'operations'],
                'dashboard' => ['profile' => 'operations'], 'landing' => 'fulfillments',
                'policies' => ['dispatch-approval'],
            ]),
            self::make('dispatcher', 'Dispatcher', $SH, 'Assigns orders to drivers and vehicles.', [
                // TASK-IAM-TEMPLATE-RECONCILIATION-001 Group C: 'logistics.dispatch.*' does not
                // exist — the real domain is bare 'dispatch.*' (two-segment, seeded directly by
                // Logistics/Dispatch migrations, not the 'logistics.' prefix). operate resolved
                // to queue+session management (the direct operate-equivalents); view resolved to
                // monitoring+audit. dispatch.assignment.{review,approve,override} are deliberately
                // NOT included — no 'operate'-equivalent action exists on that resource, and
                // adding the review/approve/override actions would be a broader grant than the
                // original single "operate" token intended.
                'permissions' => ['dispatch.monitoring.view', 'dispatch.audit.view', 'dispatch.queue.manage', 'dispatch.session.manage', 'logistics.drivers.view', 'logistics.vehicles.view'],
                'nav' => ['dashboard', 'shipping', 'logistics'],
                'dashboard' => ['profile' => 'operations', 'hidden' => ['sales-revenue', 'marketing-perf']],
                'landing' => 'fulfillments', 'hidden' => self::HIDE_SALES,
            ]),
            self::make('driver', 'Driver', $SH, 'Delivery driver — mobile, own routes only.', [
                // TASK-IAM-TEMPLATE-RECONCILIATION-001 Group C: 'logistics.deliveries.*' does not
                // exist — the real domain is bare 'delivery.*' (seeded directly by
                // Logistics/Delivery migrations). operate resolved to the three concrete
                // field-operation grants a driver actually performs (POD capture, COD collection,
                // return handling); view resolved to the one real analytics permission.
                'permissions' => ['delivery.analytics.view', 'delivery.pod.capture', 'delivery.cod.collect', 'delivery.return.manage'],
                'nav' => ['dashboard', 'shipping'],
                'dashboard' => ['profile' => 'operations', 'hidden' => ['sales-revenue', 'marketing-perf', 'ai-intelligence']],
                'landing' => 'fulfillments', 'hidden' => self::HIDE_SALES,
                // scopeFor() is an EXACT key lookup (EffectiveRoleProfile::scopeFor(), not a
                // prefix match) against resourceOf($permissionName) — "sales.orders.view" scopes
                // under "sales.orders", so each of these four permissions scopes under its own
                // two-segment resource key. A single 'delivery' => 'self' entry would silently
                // match none of them and leave the driver unscoped (defaulting to 'all') — this
                // was verified directly against ScopeResolver/EffectiveRoleProfile before writing it.
                'scopes' => [
                    'delivery.pod' => 'self',
                    'delivery.cod' => 'self',
                    'delivery.return' => 'self',
                    'delivery.analytics' => 'self',
                ],
            ]),

            // ── Sales ────────────────────────────────────────────────────────────
            self::make('sales-manager', 'Sales Manager', $S, 'Runs the sales team and pipeline.', [
                'permissions' => ['sales.*', 'crm.*', 'pos.*'],
                'nav' => ['dashboard', 'commerce', 'crm', 'pos', 'customerEngagement'],
                'dashboard' => ['profile' => 'crm'], 'landing' => 'orders',
                'scopes' => ['sales.orders' => 'team', 'sales.customers' => 'team'],
                'policies' => ['discount-approval'],
            ]),
            self::make('sales-representative', 'Sales Representative', $S, 'Owns their own orders and customers — no cost visibility.', [
                // TASK-IAM-TEMPLATE-RECONCILIATION-001 Group C: 'crm.leads.create' does not
                // exist — the CRM Sales domain has no separate "leads" resource; its three real
                // tokens are crm.sales.{view,manage,convert} (config/permissions.php seeder,
                // Modules/Crm/Sales). 'crm.sales.view' was already correctly held; resolved the
                // stale create-style token to crm.sales.manage (create/update leads, opportunities,
                // quotes) + crm.sales.convert (win/lose an opportunity) — the two real write-side
                // actions a rep who owns their own pipeline actually performs day to day.
                'permissions' => [
                    'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
                    'sales.customers.view', 'sales.customers.create',
                    'crm.sales.view', 'crm.sales.manage', 'crm.sales.convert',
                    'inventory.products.view',
                ],
                'nav' => ['dashboard', 'commerce', 'crm'],
                'dashboard' => ['profile' => 'crm', 'hidden' => ['marketing-perf']],
                'landing' => 'orders', 'hidden' => self::HIDE_SALES,
                'scopes' => ['sales.orders' => 'self', 'sales.customers' => 'self'],
            ]),
            self::make('cashier', 'Cashier', $S, 'POS operator — own sessions only.', [
                // TASK-IAM-TEMPLATE-RECONCILIATION-001 Group C: 'pos.sessions.*' and
                // 'pos.sales.create' do not exist.
                // CORRECTION (this task): an earlier pass at this reconciliation invented
                // 'pos.shifts.*', 'pos.carts.*' and 'pos.payments.*' — none of these exist
                // anywhere in the codebase. Checked: config/permissions.php's canonical
                // registry (modules.pos has exactly one resource, 'terminal', with actions
                // view/operate — no shifts/carts/payments resource at all); every POS module
                // migration under Modules/POS/*/Infrastructure/Database/Migrations (all
                // schema-only, none seeds a permission); and routes/api.php, where the ENTIRE
                // POS route group — sessions, shifts, carts, cart lines, sales, returns,
                // exchanges, receipts — is gated by the single middleware
                // 'permission:pos.terminal.operate' (line ~882). There is no finer-grained
                // token for any individual POS sub-resource to reference. Resolved to the
                // real two-token pos.terminal surface only.
                'permissions' => ['pos.terminal.view', 'pos.terminal.operate', 'inventory.products.view'],
                'nav' => ['dashboard', 'pos'],
                'dashboard' => ['profile' => 'crm', 'hidden' => ['marketing-perf', 'ai-intelligence']],
                'landing' => 'pos', 'hidden' => self::HIDE_SALES,
                // scopeFor() exact-match (see the driver template's note) — this role holds no
                // sales.orders.* permission, so a sales.orders scope entry would be dead config.
                'scopes' => ['pos.terminal' => 'self'],
            ]),

            // ── Customer Service ─────────────────────────────────────────────────
            self::make('customer-service-agent', 'Customer Service Agent', $CS, 'Handles tickets, conversations and RMAs.', [
                'permissions' => ['crm.service.view', 'crm.tickets.create', 'crm.tickets.update', 'omnichannel.inbox.view', 'omnichannel.inbox.manage', 'crm.customers.view'],
                'nav' => ['dashboard', 'crm', 'customerEngagement', 'omnichannel'],
                'dashboard' => ['profile' => 'crm', 'hidden' => ['marketing-perf']],
                'landing' => 'crm', 'hidden' => self::HIDE_SALES,
                'scopes' => ['crm.tickets' => 'self'],
            ]),
            self::make('crm-specialist', 'CRM Specialist', $CS, 'Owns customer intelligence and engagement.', [
                'permissions' => ['crm.*', 'cep.*'],
                'nav' => ['dashboard', 'crm', 'customerEngagement', 'reports'],
                'dashboard' => ['profile' => 'crm'], 'landing' => 'crm',
            ]),

            // ── Marketing ────────────────────────────────────────────────────────
            self::make('marketing-specialist', 'Marketing Specialist', $MK, 'Runs campaigns and creative.', [
                'permissions' => ['marketing.*', 'bae.*'],
                'nav' => ['dashboard', 'marketing', 'crm'],
                'dashboard' => ['profile' => 'marketing'], 'landing' => 'marketing',
            ]),

            // ── Accounting / Finance ─────────────────────────────────────────────
            self::make('accountant', 'Accountant', $ACC, 'Records and reconciles transactions.', [
                'permissions' => ['finance.gl.view', 'finance.journal.create', 'accounting.ledgers.view', 'purchasing.supplier_invoices.view'],
                'nav' => ['dashboard', 'finance', 'purchasing', 'reports'],
                'dashboard' => ['profile' => 'finance'], 'landing' => 'accounting',
            ]),
            self::make('senior-accountant', 'Senior Accountant', $ACC, 'Reviews and posts journals, manages subledgers.', [
                'permissions' => ['finance.*', 'purchasing.supplier_invoices.*'],
                'nav' => ['dashboard', 'finance', 'purchasing', 'reports'],
                'dashboard' => ['profile' => 'finance'], 'landing' => 'accounting',
                'policies' => ['approve-journals'],
            ]),
            self::make('financial-controller', 'Financial Controller', $FIN, 'Owns the close, budgets and controls.', [
                // Same reasoning as `finance-director`: explicit proof verbs, no `sales.*`
                // wildcard, and `proof_upload` deliberately withheld from Finance.
                'permissions' => [
                    'finance.*',
                    'sales.orders.proof_view', 'sales.orders.proof_verify', 'sales.orders.proof_reject',
                ],
                'nav' => ['dashboard', 'finance', 'reports'],
                'dashboard' => ['profile' => 'finance'], 'landing' => 'accounting',
                'policies' => ['close-period', 'approve-journals', 'approve-budget'],
            ]),

            // ── HR ───────────────────────────────────────────────────────────────
            self::make('hr-manager', 'HR Manager', $HR, 'Runs HR operations, payroll and hiring.', [
                'permissions' => ['hr.*'],
                'nav' => ['dashboard', 'hr', 'reports'],
                'dashboard' => ['profile' => 'executive'], 'landing' => 'hr',
                'policies' => ['payroll-approval', 'hiring-approval'],
            ]),
            self::make('hr-officer', 'HR Officer', $HR, 'Handles employee records and attendance.', [
                'permissions' => ['hr.employees.view', 'hr.employees.create', 'hr.employees.update', 'hr.attendance.view', 'hr.attendance.register', 'hr.leave.view'],
                'nav' => ['dashboard', 'hr'],
                'dashboard' => ['profile' => 'executive', 'hidden' => ['sales-revenue', 'marketing-perf']],
                'landing' => 'hr',
            ]),

            // ── Administration / IT / AI ─────────────────────────────────────────
            self::make('system-administrator', 'System Administrator', $ADM, 'Administers organization, users and configuration.', [
                'permissions' => ['iam.*', 'organization.*', 'configuration.*'],
                'nav' => ['dashboard', 'administration', 'core', 'reports'],
                'dashboard' => ['profile' => 'executive'], 'landing' => 'organization',
            ]),
            self::make('support-engineer', 'Support Engineer', $IT, 'Operates the engineering and self-healing platform.', [
                'permissions' => ['engineering.*'],
                'nav' => ['dashboard', 'engineering', 'reports'],
                'dashboard' => ['profile' => 'executive'], 'landing' => 'engineeringDashboard',
            ]),
            self::make('ai-administrator', 'AI Administrator', $AI, 'Administers the AI operations platform and bridge.', [
                'permissions' => ['claude_bridge.*', 'bae.*', 'engineering.*'],
                'nav' => ['dashboard', 'engineering', 'administration', 'reports'],
                'dashboard' => ['profile' => 'executive'], 'landing' => 'engineeringDashboard',
            ]),
            self::make('ai-analyst', 'AI Analyst', $AI, 'Read-only analytics across the AI platform.', [
                // TASK-IAM-TEMPLATE-RECONCILIATION-001 Group C: none of 'bae.view',
                // 'claude_bridge.view', 'engineering.view' exist as tokens.
                // CORRECTION (this task): an earlier pass at this reconciliation invented a
                // large per-domain taxonomy (engineering.pipelines/tasks/queue/releases/repair/
                // workers/ai_reviews, claude_bridge.settings/tasks/workers, bae.attributions
                // [plural — also a typo of the real singular resource]/timeline) and cited "the
                // enterprise permission matrix migration" as evidence; that migration does not
                // touch these namespaces at all (checked directly — zero matches), and none of
                // those 11 tokens appears anywhere else in the codebase either. The only real
                // tokens for these three modules are their base view/manage pair
                // (config/permissions.php modules.{bae.attribution,claude_bridge.platform,
                // engineering.platform}); the one other genuinely real engineering permission
                // family, engineering.inbox.* (EngineeringTaskPolicy), is a distinct task-inbox
                // concern the original 'engineering.view' token gave no indication of covering,
                // so it is deliberately left out rather than guessed back in. Resolved to a
                // direct 1:1 swap of each stale module-wide token for its one real view-level
                // equivalent — no invented resource names, no widening beyond what the original
                // three tokens evidently intended.
                'permissions' => ['bae.attribution.view', 'claude_bridge.platform.view', 'engineering.platform.view'],
                'nav' => ['dashboard', 'engineering', 'reports'],
                'dashboard' => ['profile' => 'executive', 'hidden' => ['sales-revenue']],
                'landing' => 'engineeringDashboard',
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array{key:string,name:string,category:string,description:string,definition:array<string,mixed>}
     */
    private static function make(string $key, string $name, string $category, string $description, array $spec): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'category' => $category,
            'description' => $description,
            'definition' => [
                'permissions' => $spec['permissions'] ?? [],
                'deny' => $spec['deny'] ?? [],
                'visibility' => ['hidden_fields' => $spec['hidden'] ?? []],
                'scopes' => $spec['scopes'] ?? [],
                'policies' => $spec['policies'] ?? [],
                'navigation' => ['modules' => $spec['nav'] ?? []],
                'dashboard' => $spec['dashboard'] ?? ['profile' => 'executive'],
                'landing_page' => $spec['landing'] ?? 'dashboard',
                'preferences' => $spec['preferences'] ?? ['theme' => 'system', 'language' => 'en'],
                'quick_actions' => $spec['quick_actions'] ?? [],
            ],
        ];
    }
}
