<?php

declare(strict_types=1);

/**
 * ECOS ERP — Centralised Permission Registry
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  Naming convention: {domain}.{resource}.{action}                         │
 * │  e.g. "inventory.products.view"  "sales.orders.fulfill"                  │
 * │       "crm.customers.update"     "iam.roles.assign"                      │
 * │                                                                          │
 * │  Reference permissions via this file — never hardcode strings:           │
 * │    config('permissions.modules.inventory.products') → ['view','create']  │
 * │    config('permissions.all')                        → flat name list     │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return [

    // ── Structured permission registry (domain → resource → actions) ──────────
    'modules' => [

        'iam' => [
            'users' => ['view', 'create', 'update', 'delete'],
            'roles' => ['view', 'create', 'update', 'delete', 'assign'],
        ],

        'organization' => [
            'companies' => ['view', 'create', 'update', 'delete'],
            'branches' => ['view', 'create', 'update', 'delete'],
        ],

        'inventory' => [
            'products' => ['view', 'create', 'update', 'delete'],
            'warehouses' => ['view', 'create', 'update', 'delete'],
            'categories' => ['view', 'create', 'update', 'delete'],
            'units' => ['view', 'create', 'update', 'delete'],
            'stock' => ['view', 'adjust', 'receive', 'count'],
            'count' => ['view', 'create', 'update', 'delete', 'approve'],
            'recipes' => ['view', 'create', 'update', 'delete'],
            'waste' => ['view', 'resolve'],
            'liabilities' => ['view', 'approve', 'reject'],
            'abc' => ['view', 'recalculate'],
            'price_review' => ['view', 'update', 'approve', 'publish'],
        ],

        'purchasing' => [
            'suppliers' => ['view', 'create', 'update', 'delete'],
            'purchase_orders' => ['view', 'create', 'update', 'delete'],
            'goods_receipts' => ['view', 'create', 'update', 'delete'],
            'materials' => ['view', 'create', 'update', 'delete', 'submit', 'review', 'select_supplier', 'approve', 'cancel'],
            'material_requests' => ['view', 'create', 'edit', 'cancel', 'submit'],
            'purchases' => ['view', 'create', 'review', 'merge', 'split', 'select_supplier', 'approve', 'execute', 'cancel', 'export'],
            'supplier_returns' => ['view', 'create', 'edit', 'submit', 'approve', 'reject', 'cancel', 'complete', 'mark_sent', 'credit_pending'],
            'supplier_invoices' => ['view', 'create', 'edit', 'validate', 'post', 'cancel'],
            'receiving' => ['view', 'create', 'post', 'cancel'],
        ],

        'sales' => [
            'orders' => ['view', 'create', 'update', 'delete', 'fulfill', 'override_price'],
            'channels' => ['view', 'create', 'update', 'delete', 'sync'],
            'fulfillments' => ['view', 'create', 'update', 'delete'],
        ],

        'crm' => [
            'customers' => ['view', 'create', 'update', 'delete'],
        ],

        'logistics' => [
            'shipping' => ['view', 'quote'],
            'carriers' => ['view', 'create', 'update', 'delete'],
            'drivers' => ['view', 'create', 'update', 'delete'],
            'vehicles' => ['view', 'create', 'update', 'delete'],
            'geography' => ['view', 'create', 'update', 'delete'],
            'distribution' => ['view', 'create', 'update', 'delete'],
        ],

        'operations' => [
            'preparation' => ['view', 'create', 'update', 'delete'],
            'fulfillment' => ['view', 'manage'],
        ],

        'engineering' => [
            // Internal platform module — Super Admin / CTO / DevOps only.
            'platform' => ['view', 'manage'],
        ],

        'marketing' => [
            'workspace' => ['view', 'manage'],
        ],

        'cep' => [
            'inbox' => ['view', 'manage'],
        ],

        'omnichannel' => [
            'inbox' => ['view', 'manage'],
        ],

        'claude_bridge' => [
            // Internal AI orchestration platform — Super Admin only.
            'platform' => ['view', 'manage'],
        ],

        'bae' => [
            'attribution' => ['view', 'manage'],
        ],

        'pos' => [
            'terminal' => ['view', 'operate'],
        ],

        'configuration' => [
            'settings' => ['view', 'manage'],
        ],

    ],

    // ── Role definitions ──────────────────────────────────────────────────────
    //
    // is_system = true  → role bypasses all permission checks via Gate::before().
    // Never gate-bypass on slug — add is_system to any future privileged role.
    //
    'roles' => [
        'super-admin' => ['name' => 'Super Admin',         'is_system' => true],
        'company-admin' => ['name' => 'Company Admin',       'is_system' => false],
        'warehouse-manager' => ['name' => 'Warehouse Manager',   'is_system' => false],
        'purchasing' => ['name' => 'Purchasing',          'is_system' => false],
        'sales' => ['name' => 'Sales',               'is_system' => false],
        'inventory-operator' => ['name' => 'Inventory Operator',  'is_system' => false],
        'viewer' => ['name' => 'Viewer',              'is_system' => false],

        // ── Operator role model (TASK-OPERATOR-ROLES-001) — least-privilege, no super perms ──
        'warehouse-operator' => ['name' => 'Warehouse Operator', 'is_system' => false],
        'inventory-controller' => ['name' => 'Inventory Controller', 'is_system' => false],
        'purchasing-manager' => ['name' => 'Purchasing Manager', 'is_system' => false],
        'purchasing-officer' => ['name' => 'Purchasing Officer', 'is_system' => false],
        'sales-manager' => ['name' => 'Sales Manager', 'is_system' => false],
        'sales-representative' => ['name' => 'Sales Representative', 'is_system' => false],
        'customer-service' => ['name' => 'Customer Service', 'is_system' => false],
        'dispatcher' => ['name' => 'Dispatcher', 'is_system' => false],
        'shipping-coordinator' => ['name' => 'Shipping Coordinator', 'is_system' => false],
        'fleet-manager' => ['name' => 'Fleet Manager', 'is_system' => false],
        'driver' => ['name' => 'Driver', 'is_system' => false],
        'cashier' => ['name' => 'Cashier', 'is_system' => false],
        'marketing-manager' => ['name' => 'Marketing Manager', 'is_system' => false],
        'marketing-operator' => ['name' => 'Marketing Operator', 'is_system' => false],
        'production-manager' => ['name' => 'Production Manager', 'is_system' => false],
        'preparation-supervisor' => ['name' => 'Preparation Supervisor', 'is_system' => false],
        'fulfillment-supervisor' => ['name' => 'Fulfillment Supervisor', 'is_system' => false],
        'engineering-operator' => ['name' => 'Engineering Operator', 'is_system' => false],
        'devops-operator' => ['name' => 'DevOps Operator', 'is_system' => false],
        'system-auditor' => ['name' => 'System Auditor', 'is_system' => false],
    ],

    // ── Role → permission grants (used by RbacSeeder) ─────────────────────────
    //
    // Keys use the "domain.resource" format.
    // Super Admin has no entry — bypass lives in Gate::before() via is_system.
    //
    'role_permissions' => [

        'company-admin' => [
            'iam.users' => ['view', 'create', 'update'],
            'iam.roles' => ['view', 'assign'],
            'organization.companies' => ['view', 'create', 'update'],
            'organization.branches' => ['view', 'create', 'update', 'delete'],
            'inventory.warehouses' => ['view', 'create', 'update', 'delete'],
            'inventory.categories' => ['view', 'create', 'update', 'delete'],
            'inventory.units' => ['view', 'create', 'update', 'delete'],
            'inventory.products' => ['view', 'create', 'update', 'delete'],
            'inventory.stock' => ['view', 'adjust', 'receive', 'count'],
            'inventory.count' => ['view', 'create', 'update', 'delete', 'approve'],
            'inventory.recipes' => ['view', 'create', 'update', 'delete'],
            'inventory.waste' => ['view', 'resolve'],
            'inventory.liabilities' => ['view', 'approve', 'reject'],
            'inventory.abc' => ['view', 'recalculate'],
            'inventory.price_review' => ['view', 'update', 'approve', 'publish'],
            'purchasing.suppliers' => ['view', 'create', 'update', 'delete'],
            'purchasing.purchase_orders' => ['view', 'create', 'update', 'delete'],
            'purchasing.goods_receipts' => ['view', 'create', 'update', 'delete'],
            'purchasing.materials' => ['view', 'create', 'update', 'delete', 'submit', 'review', 'select_supplier', 'approve', 'cancel'],
            'purchasing.supplier_invoices' => ['view', 'create', 'edit', 'validate', 'post', 'cancel'],
            'purchasing.supplier_returns' => ['view', 'create', 'edit', 'submit', 'approve', 'reject', 'cancel', 'complete', 'mark_sent', 'credit_pending'],
            'crm.customers' => ['view', 'create', 'update', 'delete'],
            'sales.channels' => ['view', 'create', 'update', 'delete', 'sync'],
            'sales.orders' => ['view', 'create', 'update', 'delete', 'fulfill', 'override_price'],
            'sales.fulfillments' => ['view', 'create', 'update', 'delete'],
            'logistics.shipping' => ['view', 'quote'],
            'logistics.carriers' => ['view', 'create', 'update', 'delete'],
            'logistics.drivers' => ['view', 'create', 'update', 'delete'],
            'logistics.vehicles' => ['view', 'create', 'update', 'delete'],
            'logistics.geography' => ['view', 'create', 'update', 'delete'],
            'logistics.distribution' => ['view', 'create', 'update', 'delete'],
            'operations.preparation' => ['view', 'create', 'update', 'delete'],
            'operations.fulfillment' => ['view', 'manage'],
            'marketing.workspace' => ['view', 'manage'],
            'cep.inbox' => ['view', 'manage'],
            'omnichannel.inbox' => ['view', 'manage'],
            'bae.attribution' => ['view', 'manage'],
            'pos.terminal' => ['view', 'operate'],
            'configuration.settings' => ['view', 'manage'],
        ],

        'warehouse-manager' => [
            'inventory.warehouses' => ['view', 'create', 'update'],
            'inventory.categories' => ['view'],
            'inventory.units' => ['view'],
            'inventory.products' => ['view'],
            'inventory.stock' => ['view', 'adjust', 'receive', 'count'],
            'inventory.count' => ['view', 'create', 'update', 'approve'],
            'inventory.recipes' => ['view'],
            'inventory.waste' => ['view', 'resolve'],
            'inventory.liabilities' => ['view', 'approve', 'reject'],
            'inventory.abc' => ['view', 'recalculate'],
            'purchasing.goods_receipts' => ['view', 'create'],
            'purchasing.materials' => ['view', 'create', 'submit'],
            'operations.preparation' => ['view', 'create', 'update', 'delete'],
            'operations.fulfillment' => ['view', 'manage'],
        ],

        'purchasing' => [
            'inventory.warehouses' => ['view'],
            'inventory.categories' => ['view'],
            'inventory.units' => ['view'],
            'inventory.products' => ['view'],
            'purchasing.suppliers' => ['view', 'create', 'update', 'delete'],
            'purchasing.purchase_orders' => ['view', 'create', 'update', 'delete'],
            'purchasing.goods_receipts' => ['view', 'create', 'update', 'delete'],
            'purchasing.materials' => ['view', 'create', 'update', 'delete', 'submit', 'review', 'select_supplier', 'approve', 'cancel'],
            'purchasing.supplier_invoices' => ['view', 'create', 'edit', 'validate', 'post', 'cancel'],
            'purchasing.supplier_returns' => ['view', 'create', 'edit', 'submit', 'approve', 'reject', 'cancel', 'complete', 'mark_sent', 'credit_pending'],
        ],

        'sales' => [
            'inventory.categories' => ['view'],
            'inventory.products' => ['view'],
            'crm.customers' => ['view', 'create', 'update', 'delete'],
            'sales.channels' => ['view'],
            'sales.orders' => ['view', 'create', 'update', 'fulfill', 'override_price'],
            'sales.fulfillments' => ['view', 'create', 'update'],
            'operations.fulfillment' => ['view', 'manage'],
            'pos.terminal' => ['view', 'operate'],
        ],

        'inventory-operator' => [
            'inventory.warehouses' => ['view'],
            'inventory.categories' => ['view'],
            'inventory.units' => ['view'],
            'inventory.products' => ['view'],
            'inventory.stock' => ['view', 'adjust', 'receive', 'count'],
            'inventory.count' => ['view', 'create', 'update'],
            'inventory.recipes' => ['view'],
            'inventory.waste' => ['view'],
            'inventory.abc' => ['view'],
            'purchasing.goods_receipts' => ['view'],
        ],

        'viewer' => [
            'iam.users' => ['view'],
            'organization.companies' => ['view'],
            'organization.branches' => ['view'],
            'inventory.warehouses' => ['view'],
            'inventory.categories' => ['view'],
            'inventory.units' => ['view'],
            'inventory.products' => ['view'],
            'inventory.stock' => ['view'],
            'inventory.recipes' => ['view'],
            'inventory.waste' => ['view'],
            'inventory.liabilities' => ['view'],
            'inventory.abc' => ['view'],
            'inventory.price_review' => ['view'],
            'purchasing.suppliers' => ['view'],
            'purchasing.purchase_orders' => ['view'],
            'purchasing.goods_receipts' => ['view'],
            'purchasing.materials' => ['view'],
            'crm.customers' => ['view'],
            'sales.channels' => ['view'],
            'sales.orders' => ['view'],
            'sales.fulfillments' => ['view'],
        ],

        // ── Operator role grants (TASK-OPERATOR-ROLES-001) — existing permissions only ──

        'warehouse-operator' => [
            'inventory.warehouses' => ['view'],
            'inventory.categories' => ['view'],
            'inventory.units' => ['view'],
            'inventory.products' => ['view'],
            'inventory.stock' => ['view', 'receive', 'count'],
            'inventory.count' => ['view', 'create', 'update'],
            'inventory.waste' => ['view'],
            'purchasing.goods_receipts' => ['view', 'create'],
            'operations.preparation' => ['view', 'update'],
        ],

        'inventory-controller' => [
            'inventory.warehouses' => ['view'],
            'inventory.categories' => ['view'],
            'inventory.units' => ['view'],
            'inventory.products' => ['view', 'update'],
            'inventory.stock' => ['view', 'adjust', 'count'],
            'inventory.count' => ['view', 'create', 'update', 'delete', 'approve'],
            'inventory.waste' => ['view', 'resolve'],
            'inventory.liabilities' => ['view', 'approve', 'reject'],
            'inventory.abc' => ['view', 'recalculate'],
            'inventory.recipes' => ['view'],
            'inventory.price_review' => ['view', 'update', 'approve'],
        ],

        'purchasing-manager' => [
            'inventory.warehouses' => ['view'],
            'inventory.categories' => ['view'],
            'inventory.units' => ['view'],
            'inventory.products' => ['view'],
            'purchasing.suppliers' => ['view', 'create', 'update', 'delete'],
            'purchasing.purchase_orders' => ['view', 'create', 'update', 'delete'],
            'purchasing.goods_receipts' => ['view', 'create', 'update', 'delete'],
            'purchasing.materials' => ['view', 'create', 'update', 'delete', 'submit', 'review', 'select_supplier', 'approve', 'cancel'],
            'purchasing.material_requests' => ['view', 'create', 'edit', 'cancel', 'submit'],
            'purchasing.purchases' => ['view', 'create', 'review', 'merge', 'split', 'select_supplier', 'approve', 'execute', 'cancel', 'export'],
            'purchasing.supplier_invoices' => ['view', 'create', 'edit', 'validate', 'post', 'cancel'],
            'purchasing.supplier_returns' => ['view', 'create', 'edit', 'submit', 'approve', 'reject', 'cancel', 'complete', 'mark_sent', 'credit_pending'],
            'purchasing.receiving' => ['view', 'create', 'post', 'cancel'],
        ],

        'purchasing-officer' => [
            'inventory.products' => ['view'],
            'inventory.categories' => ['view'],
            'inventory.units' => ['view'],
            'purchasing.suppliers' => ['view', 'create', 'update'],
            'purchasing.purchase_orders' => ['view', 'create', 'update'],
            'purchasing.goods_receipts' => ['view', 'create'],
            'purchasing.materials' => ['view', 'create', 'submit', 'select_supplier'],
            'purchasing.material_requests' => ['view', 'create', 'edit', 'submit'],
            'purchasing.purchases' => ['view', 'create', 'review', 'select_supplier'],
            'purchasing.supplier_invoices' => ['view', 'create', 'edit'],
            'purchasing.supplier_returns' => ['view', 'create', 'edit', 'submit'],
            'purchasing.receiving' => ['view', 'create'],
        ],

        'sales-manager' => [
            'inventory.products' => ['view'],
            'inventory.categories' => ['view'],
            'crm.customers' => ['view', 'create', 'update', 'delete'],
            'sales.channels' => ['view', 'create', 'update', 'delete', 'sync'],
            'sales.orders' => ['view', 'create', 'update', 'delete', 'fulfill', 'override_price'],
            'sales.fulfillments' => ['view', 'create', 'update', 'delete'],
            'operations.fulfillment' => ['view', 'manage'],
            'cep.inbox' => ['view', 'manage'],
            'pos.terminal' => ['view', 'operate'],
        ],

        'sales-representative' => [
            'inventory.products' => ['view'],
            'inventory.categories' => ['view'],
            'crm.customers' => ['view', 'create', 'update'],
            'sales.channels' => ['view'],
            'sales.orders' => ['view', 'create', 'update', 'fulfill'],
            'sales.fulfillments' => ['view', 'create'],
            'cep.inbox' => ['view'],
        ],

        'customer-service' => [
            'inventory.products' => ['view'],
            'crm.customers' => ['view', 'create', 'update'],
            'sales.orders' => ['view', 'update'],
            'sales.fulfillments' => ['view'],
            'cep.inbox' => ['view', 'manage'],
            'omnichannel.inbox' => ['view', 'manage'],
        ],

        'dispatcher' => [
            'sales.orders' => ['view'],
            'logistics.shipping' => ['view', 'quote'],
            'logistics.drivers' => ['view'],
            'logistics.vehicles' => ['view'],
            'logistics.geography' => ['view'],
            'logistics.distribution' => ['view', 'create', 'update'],
            'operations.fulfillment' => ['view', 'manage'],
        ],

        'shipping-coordinator' => [
            'sales.fulfillments' => ['view'],
            'logistics.shipping' => ['view', 'quote'],
            'logistics.carriers' => ['view', 'create', 'update', 'delete'],
            'logistics.geography' => ['view', 'create', 'update', 'delete'],
            'logistics.drivers' => ['view'],
            'logistics.vehicles' => ['view'],
            'logistics.distribution' => ['view', 'create', 'update', 'delete'],
            'operations.fulfillment' => ['view', 'manage'],
        ],

        'fleet-manager' => [
            'logistics.drivers' => ['view', 'create', 'update', 'delete'],
            'logistics.vehicles' => ['view', 'create', 'update', 'delete'],
            'logistics.distribution' => ['view'],
            'logistics.shipping' => ['view'],
        ],

        'driver' => [
            'logistics.shipping' => ['view'],
            'logistics.distribution' => ['view', 'update'],
        ],

        'cashier' => [
            'inventory.products' => ['view'],
            'crm.customers' => ['view'],
            'sales.orders' => ['view'],
            'pos.terminal' => ['view', 'operate'],
        ],

        'marketing-manager' => [
            'marketing.workspace' => ['view', 'manage'],
            'bae.attribution' => ['view', 'manage'],
            'sales.channels' => ['view'],
        ],

        'marketing-operator' => [
            'marketing.workspace' => ['view', 'manage'],
        ],

        'production-manager' => [
            'inventory.products' => ['view'],
            'inventory.stock' => ['view'],
            'inventory.recipes' => ['view', 'create', 'update', 'delete'],
            'operations.preparation' => ['view', 'create', 'update', 'delete'],
            'operations.fulfillment' => ['view', 'manage'],
        ],

        'preparation-supervisor' => [
            'inventory.products' => ['view'],
            'inventory.stock' => ['view'],
            'inventory.recipes' => ['view'],
            'operations.preparation' => ['view', 'create', 'update', 'delete'],
        ],

        'fulfillment-supervisor' => [
            'sales.orders' => ['view', 'update'],
            'sales.fulfillments' => ['view', 'create', 'update'],
            'operations.fulfillment' => ['view', 'manage'],
            'logistics.distribution' => ['view'],
        ],

        'engineering-operator' => [
            'engineering.platform' => ['view', 'manage'],
        ],

        'devops-operator' => [
            'engineering.platform' => ['view', 'manage'],
            'claude_bridge.platform' => ['view', 'manage'],
            'configuration.settings' => ['view'],
        ],

        // Read-only audit across the per-route-gated business domains.
        // (Group-gated modules — marketing, cep, omnichannel, pos, bae, engineering, claude_bridge —
        //  require their manage/operate permission even to read, so view-only audit of those is not
        //  effective under the current group-level gating; see report for the follow-up note.)
        'system-auditor' => [
            'iam.users' => ['view'],
            'iam.roles' => ['view'],
            'organization.companies' => ['view'],
            'organization.branches' => ['view'],
            'inventory.warehouses' => ['view'],
            'inventory.categories' => ['view'],
            'inventory.units' => ['view'],
            'inventory.products' => ['view'],
            'inventory.stock' => ['view'],
            'inventory.count' => ['view'],
            'inventory.recipes' => ['view'],
            'inventory.waste' => ['view'],
            'inventory.liabilities' => ['view'],
            'inventory.abc' => ['view'],
            'inventory.price_review' => ['view'],
            'purchasing.suppliers' => ['view'],
            'purchasing.purchase_orders' => ['view'],
            'purchasing.goods_receipts' => ['view'],
            'purchasing.materials' => ['view'],
            'purchasing.supplier_invoices' => ['view'],
            'purchasing.supplier_returns' => ['view'],
            'crm.customers' => ['view'],
            'sales.channels' => ['view'],
            'sales.orders' => ['view'],
            'sales.fulfillments' => ['view'],
            'logistics.shipping' => ['view'],
            'logistics.carriers' => ['view'],
            'logistics.drivers' => ['view'],
            'logistics.vehicles' => ['view'],
            'logistics.geography' => ['view'],
            'logistics.distribution' => ['view'],
            'operations.preparation' => ['view'],
            'operations.fulfillment' => ['view'],
        ],

    ],

];
