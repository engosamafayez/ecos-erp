<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Catalog;

/**
 * The approved BUSINESS role catalogue
 * (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001, §12 + §17).
 *
 * Declarative only. This class holds no logic and performs no writes — it is the single
 * source of truth for WHAT the fourteen approved business roles are and WHICH canonical
 * permission tokens each one holds. It is consumed by the rationalization migration
 * (which upserts each entry as a Role Template through the EXISTING
 * RoleTemplateRepository + RoleTemplateCompiler) and by the IAM read APIs that surface
 * Arabic role names.
 *
 * Three invariants this file deliberately preserves:
 *
 *  1. NO NEW PERMISSIONS. Every token below already exists in the `permissions` table.
 *     Nothing here mints a permission, and `PermissionRegistry::sync()` is still never
 *     called. A token that does not exist would be rejected by
 *     `RoleTemplateCompiler::compile()` (UnknownTemplatePermissionException) — the
 *     compiler's own fail-closed validation is the backstop, not this file.
 *
 *  2. NO SECOND AUTHORIZATION AUTHORITY. Runtime authorization stays
 *     User → Role → role_permissions, exactly as before. These entries are Role Template
 *     PRESETS; the compiler is what turns them into runtime roles.
 *
 *  3. ROLE NAMES ARE NOT HARDCODED INTO BEHAVIOUR (§18). `navigation.modules` and
 *     `scopes` are per-template DATA consumed by the existing
 *     `EffectiveRoleProfile`/`isModuleVisible()` authorities. No code anywhere branches on
 *     "if role === warehouse-manager".
 *
 * SUPER ADMIN is intentionally NOT a template here. It already exists as the
 * `super-admin` role with `is_system = 1`, and the platform grants it everything through
 * the `isSystem` bypass (PermissionService / `grants()`), not through row-level grants.
 * Compiling a `tpl-super-admin` would manufacture exactly the duplicate this task exists
 * to remove. It is listed in self::SYSTEM_PROTECTED_ROLES instead.
 */
final class BusinessRoleCatalog
{
    /**
     * Roles that must never be archived, merged or deleted by rationalization.
     *
     * @var list<string>
     */
    public const SYSTEM_PROTECTED_ROLES = ['super-admin'];

    /**
     * Role Template keys for the business catalogue are namespaced.
     *
     * Five of the approved business role names — warehouse-manager, accountant,
     * sales-manager, shipping-manager, driver — are ALREADY taken as keys by official ECOS
     * system templates, which §15 says must remain protected and untouched. Rather than
     * mutate a protected template's `is_system` flag (a silent reclassification of platform
     * data) or leave those five roles uneditable while the other eight are editable, the
     * business catalogue gets its own key namespace. `role_templates.key` is unique, so the
     * prefix is what makes the two catalogues coexist without either overwriting the other.
     */
    public const TEMPLATE_KEY_PREFIX = 'business-';

    /**
     * The runtime role slug each business role occupies.
     *
     * These are chosen, not derived. `RoleTemplateCompiler::resolveRole()` invents a
     * `tpl-<key>` slug only when the template has no `role_id` yet, so pre-linking a role
     * with a clean slug keeps the catalogue readable — the compiler is not bypassed, it is
     * simply given the role it should compile into (the same mechanism
     * RoleAuthoringService::adoptIntoTemplate() uses).
     *
     * Seven of these slugs already exist as legacy pre-template roles: `warehouse-manager`,
     * `purchasing`, `sales-manager`, `sales`, `customer-service`, `shipping-coordinator`
     * and `driver`. Those rows are ADOPTED and redefined rather than duplicated and
     * archived — the whole point of §12 is to end up with one row per business role, and
     * creating `tpl-business-sales` next to a retired `sales` would be a new duplicate
     * created by the very task that exists to remove them.
     *
     * @var array<string,string>
     */
    public const ROLE_SLUGS = [
        'warehouse-manager' => 'warehouse-manager',
        'warehouse-worker' => 'warehouse-worker',
        'purchasing' => 'purchasing',
        'accountant' => 'accountant',
        'sales-manager' => 'sales-manager',
        'sales' => 'sales',
        'moderation' => 'moderation',
        'order-confirmation' => 'order-confirmation',
        'customer-service' => 'customer-service',
        'marketing' => 'marketing',
        'shipping-manager' => 'shipping-manager',
        'shipping-coordinator' => 'shipping-coordinator',
        'driver' => 'driver',
    ];

    /** The Role Template key for a catalogue entry. */
    public static function templateKey(string $catalogKey): string
    {
        return self::TEMPLATE_KEY_PREFIX.$catalogKey;
    }

    /** The catalogue key behind a Role Template key, or null when it is not one of ours. */
    public static function catalogKeyFromTemplate(string $templateKey): ?string
    {
        if (! str_starts_with($templateKey, self::TEMPLATE_KEY_PREFIX)) {
            return null;
        }

        $candidate = substr($templateKey, strlen(self::TEMPLATE_KEY_PREFIX));

        return array_key_exists($candidate, self::ROLE_SLUGS) ? $candidate : null;
    }

    /** The runtime role slug for a catalogue entry. */
    public static function roleSlug(string $catalogKey): ?string
    {
        return self::ROLE_SLUGS[$catalogKey] ?? null;
    }

    /**
     * Role-scope expectations from §18, expressed as the ORG UNIT TYPES an administrator
     * is expected to assign for a holder of this role. Presentation guidance for the
     * Organization Scope picker — it never grants or restricts anything by itself;
     * `user_organization_assignments` plus the backend scope validation remain
     * authoritative.
     *
     * @var array<string,list<string>>
     */
    public const SCOPE_EXPECTATIONS = [
        'warehouse-manager' => ['company', 'warehouse'],
        'warehouse-worker' => ['company', 'warehouse'],
        'purchasing' => ['company', 'business_unit'],
        'accountant' => ['company'],
        'sales-manager' => ['company', 'brand', 'team', 'channel'],
        'sales' => ['brand', 'team', 'channel'],
        'moderation' => ['company', 'brand', 'channel'],
        'order-confirmation' => ['company', 'brand', 'channel'],
        'customer-service' => ['company', 'brand', 'channel'],
        'marketing' => ['company', 'brand'],
        'shipping-manager' => ['company', 'region', 'warehouse'],
        'shipping-coordinator' => ['company', 'region'],
        'driver' => ['company'],
    ];

    /**
     * The thirteen template-backed business roles, in the §12 order (Super Admin is #1 and
     * lives in SYSTEM_PROTECTED_ROLES).
     *
     * @return array<string,array{
     *     name:string, name_ar:string, description:string, description_ar:string,
     *     category:string, definition:array<string,mixed>
     * }>
     */
    public static function all(): array
    {
        return [
            // ─────────────────────────────────────────────────────────────────
            // §17.2 WAREHOUSE MANAGER
            //
            // Owns the PHYSICAL receipt execution and the preparation/loading floor.
            // Deliberately blind to commercial and financial surfaces: no Orders, no
            // Customers, no Products page, no Finance, no IAM.
            //
            // Raw materials: the role holds NO `inventory.raw_materials.*` token and NO
            // `inventory.stock.view`. That is what actually enforces "must not see the
            // warehouse on-hand / available raw-material balance" — the Raw Materials
            // page, the Inventory dashboard and the Stock Ledger are all gated on those
            // tokens. PO-receiving document quantities come from
            // `purchasing.receiving.view` + `purchasing.purchase_orders.view`, which are
            // DOCUMENT reads and expose no balance.
            //
            // Recipe/BOM: `inventory.recipes.view` only — the operational requirement.
            // No `cost.*`, no `inventory.price_review.*`, no `finance.*`: purchase cost,
            // recipe cost, COGS, profit and margin are all unreachable.
            // ─────────────────────────────────────────────────────────────────
            'warehouse-manager' => [
                'name' => 'Warehouse Manager',
                'name_ar' => 'مدير المخزن',
                'description' => 'Owns physical warehouse execution: preparation, loading, warehouse returns, supplier and purchase-order receiving, and stock counts. No commercial, costing or financial visibility.',
                'description_ar' => 'مسؤول التنفيذ الفعلي في المخزن: التحضير، التحميل، مرتجعات المخزن، استلام الموردين وأوامر الشراء، والجرد. بدون أي وصول تجاري أو مالي أو تكلفة.',
                'category' => 'warehouse',
                'definition' => [
                    'navigation' => ['modules' => ['operations', 'inventory', 'purchasing']],
                    'permissions' => [
                        // Preparation
                        'preparation.waves.view', 'preparation.waves.create', 'preparation.waves.update',
                        'preparation.waves.start', 'preparation.waves.complete',
                        'preparation.sessions.view', 'preparation.sessions.create',
                        'preparation.sessions.update', 'preparation.sessions.assign',
                        'preparation.batches.view', 'preparation.batches.create', 'preparation.batches.update',
                        'operations.view',
                        'operations.preparation.view', 'operations.preparation.create', 'operations.preparation.update',
                        'operations.fulfillment.view',
                        'operations.exception.manage',
                        // Loading
                        'loading.session.view', 'loading.session.create', 'loading.session.operate',
                        'loading.session.dispatch', 'loading.session.cancel',
                        'loading.allocation.view', 'loading.allocation.manage',
                        'loading.vehicle.assign',
                        'distribution.loading.view', 'distribution.loading.create', 'distribution.loading.update',
                        // Warehouse returns
                        'distribution.returns.view', 'distribution.returns.create', 'distribution.returns.update',
                        'purchasing.supplier_returns.view', 'purchasing.supplier_returns.create',
                        'purchasing.supplier_returns.edit', 'purchasing.supplier_returns.submit',
                        'purchasing.supplier_returns.mark_sent', 'purchasing.supplier_returns.complete',
                        // Supplier + PO receiving (physical execution)
                        'purchasing.receiving.view', 'purchasing.receiving.create',
                        'purchasing.receiving.post', 'purchasing.receiving.cancel',
                        'purchasing.goods_receipts.view', 'purchasing.goods_receipts.create',
                        'purchasing.goods_receipts.update',
                        'purchasing.purchase_orders.view',
                        'purchasing.expected_incoming.update',
                        // Stock count / inventory count
                        'inventory.count.view', 'inventory.count.create', 'inventory.count.update',
                        'inventory.count.approve',
                        'inventory.stock.count', 'inventory.stock.receive',
                        // Operational requirement only
                        'inventory.recipes.view',
                        'inventory.liabilities.view',
                        'inventory.waste.view', 'inventory.waste.create', 'inventory.waste.investigate',
                        // Operational reporting for the warehouse floor
                        'reports.preparation.view',
                    ],
                    // Machine-readable statement of the §17.2 prohibitions. These tokens are
                    // not granted above either; the deny list makes the intent explicit and
                    // survives any future wildcard grant on this template.
                    'deny' => [
                        'inventory.stock.view',
                        'inventory.stock.adjust',
                        'inventory.raw_materials.view', 'inventory.raw_materials.create',
                        'inventory.raw_materials.update', 'inventory.raw_materials.delete',
                        'inventory.products.view',
                        'inventory.price_review.view',
                        'cost.cost_management.view', 'cost.price_review.view',
                        'sales.orders.view', 'crm.customers.view',
                        'purchasing.suppliers.view',
                    ],
                    'scopes' => [
                        'preparation' => 'warehouse',
                        'loading' => 'warehouse',
                        'inventory.count' => 'warehouse',
                        'purchasing.receiving' => 'warehouse',
                    ],
                    'visibility' => [
                        'hidden_fields' => ['cost', 'unit_cost', 'purchase_price', 'supplier_price', 'cogs', 'profit', 'margin', 'stock_on_hand', 'available_qty'],
                    ],
                    'landing_page' => 'waveWorkspace',
                    'dashboard' => ['profile' => 'operations'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.3 WAREHOUSE WORKERS
            //
            // Executes assigned tasks. Detailed warehouse/inventory pages are hidden, and
            // the role owns none of the final sensitive authorities: no
            // `inventory.count.approve`, no `inventory.stock.adjust`, no
            // `purchasing.receiving.post`.
            // ─────────────────────────────────────────────────────────────────
            'warehouse-worker' => [
                'name' => 'Warehouse Workers',
                'name_ar' => 'عمال المخزن',
                'description' => 'Executes assigned preparation, loading and receiving tasks and performs stock counts. No stock balances, no masters, no approvals, no commercial or financial visibility.',
                'description_ar' => 'تنفيذ مهام التحضير والتحميل والاستلام المُسنَدة، وتنفيذ الجرد. بدون أرصدة مخزون أو بيانات أساسية أو اعتمادات أو أي وصول تجاري أو مالي.',
                'category' => 'warehouse',
                'definition' => [
                    'navigation' => ['modules' => ['operations', 'inventory']],
                    'permissions' => [
                        'inventory.count.view', 'inventory.count.update',
                        'inventory.stock.count',
                        'preparation.waves.view',
                        'preparation.sessions.view', 'preparation.sessions.update',
                        'preparation.batches.view', 'preparation.batches.update',
                        'operations.preparation.view',
                        'loading.session.view', 'loading.session.operate',
                        'purchasing.receiving.view',
                        'purchasing.goods_receipts.view', 'purchasing.goods_receipts.update',
                    ],
                    'deny' => [
                        'inventory.stock.view', 'inventory.stock.adjust',
                        'inventory.count.approve', 'inventory.count.delete',
                        'inventory.raw_materials.view', 'inventory.products.view',
                        'inventory.price_review.view',
                        'purchasing.receiving.post', 'purchasing.receiving.cancel',
                        'purchasing.suppliers.view', 'purchasing.purchase_orders.view',
                        'crm.customers.view', 'sales.orders.view',
                        'cost.cost_management.view', 'cost.price_review.view',
                    ],
                    'scopes' => [
                        'preparation' => 'warehouse',
                        'loading' => 'self',
                        'inventory.count' => 'warehouse',
                    ],
                    'visibility' => [
                        'hidden_fields' => ['cost', 'unit_cost', 'purchase_price', 'cogs', 'profit', 'margin', 'stock_on_hand', 'available_qty', 'expected_qty'],
                    ],
                    'landing_page' => 'inventoryCount',
                    'dashboard' => ['profile' => 'operations'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.4 PURCHASING
            //
            // The COMMERCIAL supplier/PO owner. Sees stock and raw materials because
            // procurement planning and shortage cover need them (§17.4 lists
            // "Procurement Planning / Shortages" as visible), but holds no
            // `inventory.stock.adjust`, no GL posting and no cash/bank administration.
            //
            // Finance is present in `navigation` for AP STATUS only — the Finance sidebar
            // items are individually permission-gated, and this role holds only
            // `finance.ap.view`, so Accounts Payable is the single Finance page it can
            // reach. "Apply Supplier Advance" is NOT granted: its route middleware names
            // `finance.ap.advance.apply`, a token that has no row in the `permissions`
            // table on DEV, so canonical policy does not currently assign it anywhere
            // (§17.4's own conditional). Recorded as a pre-existing catalogue gap rather
            // than minted here.
            // ─────────────────────────────────────────────────────────────────
            'purchasing' => [
                'name' => 'Purchasing',
                'name_ar' => 'المشتريات',
                'description' => 'Commercial owner of suppliers and purchase orders: supplier master, offerings, PO lifecycle, procurement planning and shortages, receipt monitoring and supplier invoice/AP status.',
                'description_ar' => 'المسؤول التجاري عن الموردين وأوامر الشراء: بيانات الموردين، عروضهم، دورة حياة أمر الشراء، تخطيط المشتريات والنواقص، متابعة الاستلام، وحالة فواتير الموردين والحسابات الدائنة.',
                'category' => 'operations',
                'definition' => [
                    'navigation' => ['modules' => ['purchasing', 'inventory', 'finance', 'reports']],
                    'permissions' => [
                        // Suppliers + Supplier 360
                        'purchasing.suppliers.view', 'purchasing.suppliers.create', 'purchasing.suppliers.update',
                        // Purchase orders
                        'purchasing.purchase_orders.view', 'purchasing.purchase_orders.create',
                        'purchasing.purchase_orders.update',
                        // Procurement planning / shortages
                        'purchasing.purchases.view', 'purchasing.purchases.create', 'purchasing.purchases.review',
                        'purchasing.purchases.approve', 'purchasing.purchases.cancel',
                        'purchasing.purchases.select_supplier', 'purchasing.purchases.execute',
                        'purchasing.purchases.export', 'purchasing.purchases.merge', 'purchasing.purchases.split',
                        'purchasing.materials.view', 'purchasing.materials.create', 'purchasing.materials.update',
                        'purchasing.materials.review', 'purchasing.materials.submit',
                        'purchasing.materials.approve', 'purchasing.materials.cancel',
                        'purchasing.materials.select_supplier',
                        'purchasing.material_requests.view', 'purchasing.material_requests.create',
                        'purchasing.material_requests.edit', 'purchasing.material_requests.submit',
                        'purchasing.material_requests.cancel',
                        // Receipt monitoring (NOT physical execution)
                        'purchasing.receiving.view',
                        'purchasing.goods_receipts.view',
                        'purchasing.expected_incoming.update',
                        // Supplier invoice / AP status
                        'purchasing.invoices.view',
                        'purchasing.supplier_invoices.view', 'purchasing.supplier_invoices.create',
                        'purchasing.supplier_invoices.edit', 'purchasing.supplier_invoices.validate',
                        // Supplier returns (commercial side)
                        'purchasing.supplier_returns.view', 'purchasing.supplier_returns.create',
                        'purchasing.supplier_returns.edit', 'purchasing.supplier_returns.submit',
                        'purchasing.supplier_returns.approve', 'purchasing.supplier_returns.reject',
                        'purchasing.supplier_returns.cancel', 'purchasing.supplier_returns.complete',
                        'purchasing.supplier_returns.mark_sent', 'purchasing.supplier_returns.credit_pending',
                        // Planning inputs
                        'inventory.raw_materials.view',
                        'inventory.stock.view',
                        'inventory.products.view',
                        'inventory.units.view', 'inventory.categories.view',
                        'cost.price_review.view',
                        // Supplier ledger link / AP status (read-only)
                        'finance.ap.view',
                        // Reporting
                        'reports.procurement.view', 'reports.inventory.view',
                    ],
                    'deny' => [
                        'inventory.stock.adjust', 'inventory.count.approve',
                        'purchasing.receiving.create', 'purchasing.receiving.post',
                        'purchasing.goods_receipts.create',
                        'finance.gl.view', 'finance.journal.post', 'finance.journal.create',
                        'finance.posting.manage', 'finance.cash.manage', 'finance.bank.manage',
                        'finance.ap.payment.create', 'finance.ap.payment.approve',
                        'finance.ap.bill.post',
                    ],
                    'scopes' => [
                        'purchasing' => 'company',
                        'inventory.stock' => 'company',
                    ],
                    'landing_page' => 'procurementHub',
                    'dashboard' => ['profile' => 'operations'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.5 ACCOUNTANT
            //
            // The one finance role in the approved catalogue, so it must be able to do the
            // finance work: AR, AP, GL, journals, cash, banking, treasury, statements,
            // driver settlement (financial side) and financial reports.
            //
            // `finance.*` is a wildcard the compiler expands against the real catalogue.
            // Two structural authorities are denied because §17.5's action list does not
            // include them and they are irreversible period-structure operations:
            // year-end finalize and reopening a closed period.
            // ─────────────────────────────────────────────────────────────────
            'accountant' => [
                'name' => 'Accountant',
                'name_ar' => 'محاسب',
                'description' => 'Owns the financial ledger: receivables, payables, general ledger, journals, cash, banking and treasury, customer and supplier statements, driver settlement (financial side) and financial reports.',
                'description_ar' => 'مسؤول الدفاتر المالية: حسابات العملاء والموردين، الأستاذ العام، القيود، الصندوق والبنوك والخزينة، كشوف العملاء والموردين، الجانب المالي لتسوية المندوبين، والتقارير المالية.',
                'category' => 'accounting',
                'definition' => [
                    'navigation' => ['modules' => ['finance', 'reports']],
                    'permissions' => [
                        'finance.*',
                        'accounting.*',
                        'reports.finance.view',
                    ],
                    'deny' => [
                        'finance.yearend.finalize',
                        'finance.period.reopen',
                    ],
                    'scopes' => ['finance' => 'company', 'accounting' => 'company'],
                    'landing_page' => 'accounting',
                    'dashboard' => ['profile' => 'finance'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.6 SALES MANAGER
            //
            // Commercial ownership of customers and orders plus the sales catalogue,
            // dashboard, reports and team performance. Warehouse stock detail, raw
            // material balances, COGS, Finance and IAM are all absent.
            // ─────────────────────────────────────────────────────────────────
            'sales-manager' => [
                'name' => 'Sales Manager',
                'name_ar' => 'مدير المبيعات',
                'description' => 'Commercial ownership of customers and orders, the product sales catalogue, the sales dashboard, sales reports and team performance, including policy-level commercial approvals.',
                'description_ar' => 'الملكية التجارية للعملاء والطلبات، كتالوج بيع الأصناف، لوحة المبيعات، تقارير المبيعات وأداء الفريق، مع الاعتمادات التجارية وفق السياسة.',
                'category' => 'sales',
                'definition' => [
                    'navigation' => ['modules' => ['commerce', 'crm', 'reports']],
                    'permissions' => [
                        'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
                        'sales.orders.override_price', 'sales.orders.fulfill',
                        'sales.orders.proof_view', 'sales.orders.proof_upload', 'sales.orders.proof_verify',
                        'sales.customers.view', 'sales.customers.create', 'sales.customers.update',
                        'sales.customers.export', 'sales.customers.merge',
                        'sales.channels.view',
                        'crm.customers.view', 'crm.customers.create', 'crm.customers.update',
                        'crm.customers.merge',
                        'crm.sales.view', 'crm.sales.manage', 'crm.sales.convert',
                        'crm.engagement.view', 'crm.engagement.log', 'crm.engagement.task.manage',
                        'crm.service.view',
                        'crm.intelligence.view',
                        'crm.executive.view', 'crm.executive.report', 'crm.executive.export',
                        'crm.loyalty.view',
                        'inventory.products.view',
                        'organization.teams.view',
                        'reports.sales.view', 'reports.customers.view', 'reports.products.view',
                    ],
                    'deny' => [
                        'inventory.stock.view', 'inventory.stock.adjust',
                        'inventory.raw_materials.view', 'inventory.recipes.view',
                        'inventory.price_review.view',
                        'cost.cost_management.view', 'cost.price_review.view',
                        'finance.ar.view', 'finance.ap.view', 'finance.gl.view',
                    ],
                    'scopes' => [
                        'sales' => 'brand',
                        'crm.customers' => 'brand',
                    ],
                    'visibility' => ['hidden_fields' => ['cost', 'unit_cost', 'cogs', 'purchase_price', 'margin']],
                    'landing_page' => 'orders',
                    'dashboard' => ['profile' => 'sales'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.7 SALES
            //
            // Creates customers and orders and edits them before lifecycle lock. FINAL
            // order confirmation is deliberately withheld: `sales.orders.update` is the
            // canonical token behind `POST /orders/{order}/confirm-customer`, so it is
            // granted (the role must be able to edit a pre-lock order), while
            // `override_price`, `fulfill`, `proof_verify` and `delete` — the authorities
            // that finalise an order commercially — are denied.
            // ─────────────────────────────────────────────────────────────────
            'sales' => [
                'name' => 'Sales',
                'name_ar' => 'مندوب مبيعات',
                'description' => 'Creates customers and orders, edits allowed customer fields, edits an order before lifecycle lock, and records notes and follow-up. No final commercial authority, no finance, no inventory administration.',
                'description_ar' => 'إنشاء العملاء والطلبات، تعديل بيانات العميل المسموح بها، تعديل الطلب قبل قفل دورة حياته، وتسجيل الملاحظات والمتابعة. بدون سلطة تجارية نهائية أو مالية أو إدارة مخازن.',
                'category' => 'sales',
                'definition' => [
                    'navigation' => ['modules' => ['commerce', 'crm']],
                    'permissions' => [
                        'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
                        'sales.orders.proof_view', 'sales.orders.proof_upload',
                        'sales.customers.view', 'sales.customers.create', 'sales.customers.update',
                        'crm.customers.view', 'crm.customers.create', 'crm.customers.update',
                        'crm.engagement.view', 'crm.engagement.log', 'crm.engagement.task.manage',
                        'crm.sales.view',
                        'inventory.products.view',
                    ],
                    'deny' => [
                        'sales.orders.delete', 'sales.orders.override_price',
                        'sales.orders.fulfill', 'sales.orders.proof_verify',
                        'crm.customers.delete', 'crm.customers.archive', 'crm.customers.merge',
                        'inventory.stock.view', 'inventory.stock.adjust',
                        'inventory.raw_materials.view',
                        'cost.cost_management.view', 'cost.price_review.view',
                        'finance.ar.view', 'finance.ap.view',
                    ],
                    'scopes' => [
                        'sales' => 'channel',
                        'crm.customers' => 'channel',
                    ],
                    'visibility' => ['hidden_fields' => ['cost', 'unit_cost', 'cogs', 'purchase_price', 'margin']],
                    'landing_page' => 'orders',
                    'dashboard' => ['profile' => 'sales'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.8 MODERATION
            //
            // Customers, Products, Orders, Shipping Orders and Omnichannel conversations.
            // It CREATES new orders and EDITS existing ones (`sales.orders.create` +
            // `sales.orders.update`).
            //
            // `operations` is in `navigation` for the Shipping Orders page only. Every
            // other Operations sidebar item is gated on a token this role does not hold —
            // Distribution Planning needs `logistics.distribution.view` and driver
            // assignment needs the dispatch/loading tokens, and both are explicitly
            // denied per §17.8's "Do NOT grant Distribution planning or Driver
            // Assignment automatically".
            // ─────────────────────────────────────────────────────────────────
            'moderation' => [
                'name' => 'Moderation',
                'name_ar' => 'المراجعة والإشراف',
                'description' => 'Creates and edits orders within the allowed lifecycle, maintains the customer data order handling needs, follows up shipping orders, and handles conversations and moderation actions.',
                'description_ar' => 'إنشاء الطلبات وتعديل الطلبات القائمة داخل دورة الحياة المسموح بها، وتحديث بيانات العملاء اللازمة لمعالجة الطلب، ومتابعة أوامر الشحن، وإدارة المحادثات وإجراءات المراجعة.',
                'category' => 'operations',
                'definition' => [
                    'navigation' => ['modules' => ['commerce', 'crm', 'omnichannel', 'operations']],
                    'permissions' => [
                        'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
                        'sales.orders.proof_view', 'sales.orders.proof_upload',
                        'sales.customers.view', 'sales.customers.create', 'sales.customers.update',
                        'crm.customers.view', 'crm.customers.create', 'crm.customers.update',
                        'crm.engagement.view', 'crm.engagement.log', 'crm.engagement.task.manage',
                        'crm.service.view', 'crm.service.assign', 'crm.service.resolve',
                        'crm.kb.view',
                        'inventory.products.view',
                        // Shipping Orders — view / follow-up is REQUIRED (§17.8)
                        'logistics.shipping.view',
                        // Conversations
                        'omnichannel.inbox.view', 'omnichannel.inbox.manage',
                        'omnichannel.conversations.view', 'omnichannel.conversations.create',
                        'omnichannel.conversations.update', 'omnichannel.conversations.assign',
                        'omnichannel.macros.view',
                    ],
                    'deny' => [
                        // Distribution planning and driver assignment — NOT automatic (§17.8)
                        'logistics.distribution.view', 'logistics.distribution.create',
                        'logistics.distribution.update', 'logistics.distribution.delete',
                        'dispatch.view', 'dispatch.manage', 'dispatch.propose', 'dispatch.release',
                        'dispatch.assignment.approve', 'dispatch.assignment.override',
                        'loading.vehicle.assign', 'loading.session.dispatch',
                        // Finance, stock mutation
                        'finance.ar.view', 'finance.ap.view', 'finance.gl.view',
                        'inventory.stock.adjust', 'inventory.stock.view',
                        'inventory.raw_materials.view',
                        'cost.cost_management.view', 'cost.price_review.view',
                    ],
                    'scopes' => [
                        'sales' => 'channel',
                        'crm.customers' => 'channel',
                    ],
                    'landing_page' => 'orders',
                    'dashboard' => ['profile' => 'operations'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.9 ORDER CONFIRMATION
            //
            // Customers, Products, Orders and the confirmation queue. Confirm / return for
            // correction / schedule / reschedule / notes / allowed data correction all run
            // through the canonical `sales.orders.update` token (the same token
            // `POST /orders/{order}/confirm-customer` is gated on).
            //
            // Shipping Orders is NOT granted — §17.9 says it is not required for this
            // role, and no canonical workflow on DEV proves otherwise.
            // `sales.orders.override_price` is denied: "unrestricted price changes outside
            // policy" are not allowed.
            // ─────────────────────────────────────────────────────────────────
            'order-confirmation' => [
                'name' => 'Order Confirmation',
                'name_ar' => 'تأكيد الطلبات',
                'description' => 'Works the order confirmation queue: creates and edits orders within the allowed lifecycle, confirms, returns for correction, schedules and reschedules, and corrects allowed customer data.',
                'description_ar' => 'العمل على قائمة تأكيد الطلبات: إنشاء الطلبات وتعديلها داخل دورة الحياة المسموح بها، التأكيد، الإرجاع للتصحيح، الجدولة وإعادة الجدولة، وتصحيح بيانات العميل المسموح بها.',
                'category' => 'operations',
                'definition' => [
                    'navigation' => ['modules' => ['commerce', 'crm']],
                    'permissions' => [
                        'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
                        'sales.orders.proof_view',
                        'sales.customers.view', 'sales.customers.create', 'sales.customers.update',
                        'crm.customers.view', 'crm.customers.create', 'crm.customers.update',
                        'crm.engagement.view', 'crm.engagement.log', 'crm.engagement.task.manage',
                        'inventory.products.view',
                    ],
                    'deny' => [
                        'sales.orders.override_price', 'sales.orders.delete', 'sales.orders.fulfill',
                        'logistics.shipping.view',
                        'finance.ar.view', 'finance.ap.view', 'finance.gl.view',
                        'inventory.stock.adjust', 'inventory.stock.view',
                        'inventory.raw_materials.view',
                        'cost.cost_management.view', 'cost.price_review.view',
                    ],
                    'scopes' => [
                        'sales' => 'channel',
                        'crm.customers' => 'channel',
                    ],
                    'landing_page' => 'orders',
                    'dashboard' => ['profile' => 'operations'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.10 CUSTOMER SERVICE
            //
            // Customers, Customer 360, order history/status, shipping status,
            // conversations, complaints, and payment status READ-ONLY.
            //
            // `finance` is in `navigation` purely so the Accounts Receivable page can be
            // reached: this role holds `finance.ar.view` and nothing else in Finance, and
            // every posting/receipt/write-off token is denied.
            // ─────────────────────────────────────────────────────────────────
            'customer-service' => [
                'name' => 'Customer Service',
                'name_ar' => 'خدمة العملاء',
                'description' => 'Serves customers: contact-data updates, notes, complaints and follow-up, order history and status, shipping status, conversations, and read-only payment status.',
                'description_ar' => 'خدمة العملاء: تحديث بيانات التواصل، الملاحظات، الشكاوى والمتابعة، سجل الطلبات وحالتها، حالة الشحن، المحادثات، وحالة السداد للعرض فقط.',
                'category' => 'customer_service',
                'definition' => [
                    'navigation' => ['modules' => ['crm', 'commerce', 'omnichannel', 'operations', 'finance']],
                    'permissions' => [
                        'crm.customers.view', 'crm.customers.update',
                        'crm.service.view', 'crm.service.assign', 'crm.service.resolve',
                        'crm.engagement.view', 'crm.engagement.log', 'crm.engagement.task.manage',
                        'crm.kb.view',
                        'crm.loyalty.view',
                        'sales.customers.view',
                        'sales.orders.view',
                        'logistics.shipping.view',
                        'omnichannel.inbox.view', 'omnichannel.conversations.view',
                        'omnichannel.conversations.create', 'omnichannel.conversations.update',
                        'omnichannel.macros.view',
                        // Payment status — read-only
                        'finance.ar.view',
                        'reports.customers.view',
                    ],
                    'deny' => [
                        'finance.ar.invoice.create', 'finance.ar.invoice.post',
                        'finance.ar.receipt.create', 'finance.ar.writeoff',
                        'finance.journal.create', 'finance.journal.post', 'finance.gl.view',
                        'finance.cash.manage', 'finance.bank.manage',
                        'sales.orders.create', 'sales.orders.update', 'sales.orders.delete',
                        'inventory.stock.adjust', 'inventory.stock.view',
                        'inventory.products.view',
                        'purchasing.suppliers.view', 'purchasing.purchase_orders.view',
                    ],
                    'scopes' => [
                        'crm.customers' => 'channel',
                        'sales.orders' => 'channel',
                    ],
                    'landing_page' => 'crmCustomers',
                    'dashboard' => ['profile' => 'service'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.11 MARKETING
            //
            // Campaigns, segments, audiences, attribution, BAE, leads, marketing
            // analytics, and the products MARKETING view.
            //
            // §17.11's preference — "audience/segment access rather than unrestricted full
            // operational history" — is honoured literally: `crm.customers.view` is DENIED.
            // Audience reach comes from `marketing.segments.*` and `cep.leads.*`.
            // `commerce` is in navigation only so the Products page resolves; Orders and
            // Customers there are gated on tokens this role does not hold.
            // ─────────────────────────────────────────────────────────────────
            'marketing' => [
                'name' => 'Marketing',
                'name_ar' => 'التسويق',
                'description' => 'Owns campaigns, segments and audiences, attribution and BAE, leads and marketing analytics, plus the product marketing view. Audience/segment access rather than full operational customer history.',
                'description_ar' => 'مسؤول الحملات والشرائح والجماهير، والإحالة وتحليل الأثر التجاري، والعملاء المحتملين وتحليلات التسويق، وعرض الأصناف التسويقي. الوصول عبر الجماهير والشرائح لا عبر السجل التشغيلي الكامل للعميل.',
                'category' => 'marketing',
                'definition' => [
                    'navigation' => ['modules' => ['marketing', 'customerEngagement', 'commerce', 'reports']],
                    'permissions' => [
                        'marketing.*',
                        'bae.*',
                        'cep.inbox.view',
                        'cep.leads.view', 'cep.leads.create', 'cep.leads.update',
                        'cep.leads.qualify', 'cep.leads.disqualify',
                        'cep.conversations.view',
                        'cep.notes.view', 'cep.notes.create', 'cep.notes.update',
                        'inventory.products.view',
                        'sales.channels.view',
                        'reports.products.view', 'reports.customers.view',
                    ],
                    'deny' => [
                        'crm.customers.view', 'crm.customers.create', 'crm.customers.update',
                        'crm.customers.delete', 'crm.customers.archive', 'crm.customers.merge',
                        'sales.orders.view', 'sales.orders.create', 'sales.orders.update',
                        'finance.ar.view', 'finance.ap.view', 'finance.gl.view',
                        'inventory.stock.adjust', 'inventory.stock.view',
                    ],
                    'scopes' => ['marketing' => 'brand'],
                    'landing_page' => 'marketing',
                    'dashboard' => ['profile' => 'marketing'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.12 SHIPPING MANAGER
            //
            // Plans and runs distribution: shipping orders, planning, groups, trips,
            // drivers, vehicles, loading status, delivery outcomes, POD, exceptions,
            // returns, custody summary and settlement status.
            //
            // `finance.driver.view` is granted for SETTLEMENT STATUS, and `finance` is
            // deliberately ABSENT from `navigation` — the Driver Day Settlement entry
            // lives in the Operations sidebar, so the status is reachable without the
            // Finance module ever appearing. Treasury receipt, GL and physical warehouse
            // receipt are denied.
            // ─────────────────────────────────────────────────────────────────
            'shipping-manager' => [
                'name' => 'Shipping Manager',
                'name_ar' => 'مدير الشحن',
                'description' => 'Plans and runs distribution end to end: shipping orders, distribution planning and groups, trips, drivers and vehicles, loading status, delivery outcomes and POD, exceptions, returns, custody and settlement status.',
                'description_ar' => 'تخطيط وتشغيل التوزيع بالكامل: أوامر الشحن، تخطيط التوزيع والمجموعات، الرحلات، المندوبون والمركبات، حالة التحميل، نتائج التسليم وإثباته، الاستثناءات، المرتجعات، العهدة وحالة التسوية.',
                'category' => 'shipping',
                'definition' => [
                    'navigation' => ['modules' => ['shipping', 'operations', 'reports']],
                    'permissions' => [
                        'logistics.shipping.view', 'logistics.shipping.quote',
                        'logistics.distribution.view', 'logistics.distribution.create',
                        'logistics.distribution.update', 'logistics.distribution.delete',
                        'logistics.drivers.view', 'logistics.drivers.create', 'logistics.drivers.update',
                        'logistics.vehicles.view', 'logistics.vehicles.create', 'logistics.vehicles.update',
                        'logistics.carriers.view',
                        'logistics.geography.view',
                        'geography.zones.view', 'geography.cities.view', 'geography.governorates.view',
                        'distribution.trips.view', 'distribution.trips.create', 'distribution.trips.update',
                        'distribution.trips.load', 'distribution.trips.unload',
                        'distribution.stops.view', 'distribution.stops.create', 'distribution.stops.update',
                        'distribution.stops.generate', 'distribution.stops.start',
                        'distribution.stops.complete', 'distribution.stops.proof',
                        'distribution.loading.view',
                        'distribution.returns.view', 'distribution.returns.create', 'distribution.returns.update',
                        'distribution.custody.view',
                        'distribution.exceptions.view', 'distribution.exceptions.create',
                        'distribution.exceptions.update',
                        'dispatch.view', 'dispatch.propose', 'dispatch.release', 'dispatch.manage',
                        'dispatch.queue.manage', 'dispatch.session.manage',
                        'dispatch.assignment.review', 'dispatch.assignment.approve',
                        'dispatch.assignment.override',
                        'dispatch.monitoring.view', 'dispatch.conflict.resolve', 'dispatch.audit.view',
                        'delivery.view', 'delivery.execute', 'delivery.retry', 'delivery.cancel',
                        'delivery.pod.capture', 'delivery.pod.validate',
                        'delivery.return.manage', 'delivery.analytics.view',
                        'delivery.cod.verify',
                        'loading.session.view', 'loading.allocation.view', 'loading.vehicle.assign',
                        'fleet.view', 'fleet.manage', 'fleet.cost.view', 'fleet.health.override',
                        'fleet.maintenance.schedule', 'fleet.maintenance.complete',
                        'fleet.inspection.perform', 'fleet.inspection.approve',
                        'fleet.fuel.record',
                        'routing.view', 'routing.optimize',
                        'network.view', 'network.capacity.manage', 'network.capacity.commit',
                        'carrier.view', 'carrier.manage',
                        // Settlement STATUS only — no treasury authority
                        'finance.driver.view',
                        'reports.distribution.view', 'reports.drivers.view',
                    ],
                    'deny' => [
                        'finance.ar.receipt.create', 'finance.gl.view',
                        'finance.journal.create', 'finance.journal.post',
                        'finance.cash.manage', 'finance.bank.manage',
                        'delivery.cod.collect',
                        'purchasing.receiving.create', 'purchasing.receiving.post',
                        'purchasing.goods_receipts.create',
                        'inventory.stock.adjust', 'inventory.count.approve',
                    ],
                    'scopes' => [
                        'logistics' => 'region',
                        'distribution' => 'region',
                        'delivery' => 'region',
                    ],
                    'landing_page' => 'shippingOrders',
                    'dashboard' => ['profile' => 'operations'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.13 SHIPPING COMPANY RESPONSIBLE / COORDINATOR
            //
            // The external carrier's coordinator. Everything is carrier-scoped: the
            // `scopes` map pins every logistics/distribution/delivery resource to
            // `business_unit`, the DataScope case the platform already carries for a
            // non-geographic organizational partition, and the carrier assignment itself
            // is an organization-scope assignment on the user.
            //
            // `logistics.carriers.view` is DENIED — that is the carrier ADMIN directory
            // (every shipping company). `carrier.view` is granted instead: the coordinator
            // sees their own assigned carrier only.
            // ─────────────────────────────────────────────────────────────────
            'shipping-coordinator' => [
                'name' => 'Shipping Company Coordinator',
                'name_ar' => 'مسؤول / منسق شركة الشحن',
                'description' => 'Coordinates delivery for one assigned carrier: shipping orders, trips, drivers, delivery status and POD, failed deliveries and exceptions, and the customer delivery data delivery needs. Never other carriers.',
                'description_ar' => 'تنسيق التسليم لشركة شحن واحدة مُسنَدة: أوامر الشحن، الرحلات، المندوبون، حالة التسليم وإثباته، حالات الفشل والاستثناءات، وبيانات تسليم العميل اللازمة. بدون أي وصول لشركات الشحن الأخرى.',
                'category' => 'shipping',
                'definition' => [
                    'navigation' => ['modules' => ['shipping', 'operations']],
                    'permissions' => [
                        'logistics.shipping.view',
                        'logistics.drivers.view',
                        'carrier.view',
                        'distribution.trips.view', 'distribution.trips.update',
                        'distribution.stops.view', 'distribution.stops.update',
                        'distribution.stops.start', 'distribution.stops.complete',
                        'distribution.stops.proof',
                        'distribution.exceptions.view', 'distribution.exceptions.create',
                        'distribution.exceptions.update',
                        'distribution.returns.view',
                        'delivery.view', 'delivery.execute', 'delivery.retry',
                        'delivery.pod.capture', 'delivery.return.manage',
                        'dispatch.view', 'dispatch.monitoring.view',
                    ],
                    'deny' => [
                        'logistics.carriers.view', 'logistics.carriers.create',
                        'logistics.carriers.update', 'logistics.carriers.delete',
                        'carrier.manage',
                        'logistics.distribution.create', 'logistics.distribution.update',
                        'logistics.distribution.delete',
                        'dispatch.manage', 'dispatch.release', 'dispatch.propose',
                        'logistics.vehicles.create', 'logistics.vehicles.update',
                        'purchasing.suppliers.view', 'purchasing.receiving.view',
                        'inventory.stock.view', 'inventory.count.view',
                        'finance.ar.view', 'finance.ap.view', 'finance.driver.view',
                        'delivery.cod.collect', 'delivery.cod.verify',
                    ],
                    'scopes' => [
                        'logistics' => 'business_unit',
                        'distribution' => 'business_unit',
                        'delivery' => 'business_unit',
                        'carrier' => 'business_unit',
                    ],
                    'visibility' => ['hidden_fields' => ['customer_balance', 'customer_ledger', 'total_due', 'cost', 'margin']],
                    'landing_page' => 'shippingOrders',
                    'dashboard' => ['profile' => 'operations'],
                ],
            ],

            // ─────────────────────────────────────────────────────────────────
            // §17.14 DRIVER
            //
            // The permission set is EXACTLY the two tokens the driver runtime already
            // uses, unchanged from the canonical `driver` role on DEV:
            // `loading.driver.operate` and `logistics.shipping.view`.
            //
            // This is not an under-specification. `/api/driver/*` — trips, stops, POD,
            // failure outcomes, cash declaration, expenses, advances, custody, wallet and
            // settlement status — is ONE route group gated by
            // `permission:loading.driver.operate` and fail-closed self-scoped server-side
            // to `logistics_drivers.user_id = Auth::id()`. Every §17.14 "VISIBLE ONLY FOR
            // SELF" item is therefore already enforced by that group, and every §17.14
            // "NOT ALLOWED" item follows from holding nothing else.
            //
            // Adding ANY other `sales.*`, `inventory.*`, `crm.*`, `finance.*` or
            // `logistics.distribution.*` token would also break `isDriverOnly()`
            // (post-login-landing.ts) and route drivers into the enterprise shell instead
            // of the driver app. The set is intentionally exactly two.
            // ─────────────────────────────────────────────────────────────────
            'driver' => [
                'name' => 'Driver',
                'name_ar' => 'مندوب التوصيل',
                'description' => 'Operates the driver app on their OWN trips only: my trips and stops, assigned orders, delivery data and navigation, COD amounts, own vehicle custody, own expenses and own settlement status.',
                'description_ar' => 'تشغيل تطبيق المندوب على رحلاته الخاصة فقط: رحلاتي ونقاط التوقف، الطلبات المُسنَدة، بيانات التسليم والملاحة، مبالغ التحصيل، عهدة مركبته، مصروفاته، وحالة تسويته.',
                'category' => 'shipping',
                'definition' => [
                    // The driver app is the DriverShell (/driver/*), not an ERP module —
                    // `isDriverOnly()` routes a driver-only identity there on login.
                    'navigation' => ['modules' => []],
                    'permissions' => [
                        'loading.driver.operate',
                        'logistics.shipping.view',
                    ],
                    'deny' => [
                        'logistics.distribution.view', 'logistics.distribution.update',
                        'logistics.drivers.view',
                        'crm.customers.view', 'sales.orders.view', 'inventory.products.view',
                        'inventory.stock.view',
                        'finance.ar.view', 'finance.ap.view', 'finance.driver.view',
                        'delivery.cod.verify',
                    ],
                    'scopes' => [
                        'distribution' => 'self',
                        'delivery' => 'self',
                        'loading.driver' => 'self',
                    ],
                    'visibility' => ['hidden_fields' => ['cost', 'profit', 'margin', 'purchase_prices', 'supplier_prices', 'customer_balance']],
                    'landing_page' => 'driverHome',
                    'dashboard' => ['profile' => 'operations'],
                ],
            ],
        ];
    }

    /** The catalogue keys, in approved order. @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * The org-unit types §18 expects to be assigned for a holder of this role.
     *
     * Accepts ONLY a namespaced Role Template key (`business-sales-manager`) — deliberately
     * NOT a bare catalogue key (`sales-manager`) as a fallback. Five of the thirteen
     * catalogue keys (`accountant`, `driver`, `sales-manager`, `shipping-manager`,
     * `warehouse-manager`) are ALSO, coincidentally, the literal `key` of a pre-existing,
     * unrelated, protected ECOS SYSTEM template (see ROLE_SLUGS's own docblock — those five
     * business roles reuse a legacy ROLE slug, not a system TEMPLATE key). A bare-key
     * fallback here would therefore mislabel that unrelated system template with THIS
     * catalogue's Arabic name/description the moment its own key happened to match — a
     * real defect this task's own bounded verification caught live in the Role Templates
     * tab. Every real caller (RoleController, UserController, RoleTemplateController) only
     * ever holds a TEMPLATE key here, never a bare catalogue key, so requiring the prefix
     * costs nothing and closes that collision permanently.
     *
     * @return list<string>
     */
    public static function scopeExpectationFor(string $key): array
    {
        $catalogKey = self::catalogKeyFromTemplate($key);

        return $catalogKey !== null ? (self::SCOPE_EXPECTATIONS[$catalogKey] ?? []) : [];
    }

    /**
     * Arabic display metadata for one business role, or null when it is not part of the
     * approved catalogue.
     *
     * Accepts ONLY the namespaced Role Template key (`business-sales-manager`) — see
     * scopeExpectationFor()'s docblock for why a bare-catalogue-key fallback is deliberately
     * NOT supported here.
     *
     * @return array{name:string,name_ar:string,description_ar:string}|null
     */
    public static function displayFor(string $key): ?array
    {
        $catalogKey = self::catalogKeyFromTemplate($key);
        $entry = $catalogKey !== null ? self::all()[$catalogKey] ?? null : null;

        if ($entry === null) {
            return null;
        }

        return [
            'name' => $entry['name'],
            'name_ar' => $entry['name_ar'],
            'description_ar' => $entry['description_ar'],
        ];
    }
}
