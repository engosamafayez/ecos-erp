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
            'branches'  => ['view', 'create', 'update', 'delete'],
        ],

        'inventory' => [
            'products'   => ['view', 'create', 'update', 'delete'],
            'warehouses' => ['view', 'create', 'update', 'delete'],
            'categories' => ['view', 'create', 'update', 'delete'],
            'units'      => ['view', 'create', 'update', 'delete'],
            'stock'      => ['view', 'adjust', 'receive', 'count'],
        ],

        'purchasing' => [
            'suppliers'       => ['view', 'create', 'update', 'delete'],
            'purchase_orders' => ['view', 'create', 'update', 'delete'],
            'goods_receipts'  => ['view', 'create', 'update', 'delete'],
        ],

        'sales' => [
            'orders'       => ['view', 'create', 'update', 'delete', 'fulfill'],
            'channels'     => ['view', 'create', 'update', 'delete', 'sync'],
            'fulfillments' => ['view', 'create', 'update', 'delete'],
        ],

        'crm' => [
            'customers' => ['view', 'create', 'update', 'delete'],
        ],

    ],

    // ── Role definitions ──────────────────────────────────────────────────────
    //
    // is_system = true  → role bypasses all permission checks via Gate::before().
    // Never gate-bypass on slug — add is_system to any future privileged role.
    //
    'roles' => [
        'super-admin'        => ['name' => 'Super Admin',         'is_system' => true],
        'company-admin'      => ['name' => 'Company Admin',       'is_system' => false],
        'warehouse-manager'  => ['name' => 'Warehouse Manager',   'is_system' => false],
        'purchasing'         => ['name' => 'Purchasing',          'is_system' => false],
        'sales'              => ['name' => 'Sales',               'is_system' => false],
        'inventory-operator' => ['name' => 'Inventory Operator',  'is_system' => false],
        'viewer'             => ['name' => 'Viewer',              'is_system' => false],
    ],

    // ── Role → permission grants (used by RbacSeeder) ─────────────────────────
    //
    // Keys use the "domain.resource" format.
    // Super Admin has no entry — bypass lives in Gate::before() via is_system.
    //
    'role_permissions' => [

        'company-admin' => [
            'iam.users'                  => ['view', 'create', 'update'],
            'iam.roles'                  => ['view', 'assign'],
            'organization.companies'     => ['view', 'create', 'update'],
            'organization.branches'      => ['view', 'create', 'update', 'delete'],
            'inventory.warehouses'       => ['view', 'create', 'update', 'delete'],
            'inventory.categories'       => ['view', 'create', 'update', 'delete'],
            'inventory.units'            => ['view', 'create', 'update', 'delete'],
            'inventory.products'         => ['view', 'create', 'update', 'delete'],
            'inventory.stock'            => ['view', 'adjust', 'receive', 'count'],
            'purchasing.suppliers'       => ['view', 'create', 'update', 'delete'],
            'purchasing.purchase_orders' => ['view', 'create', 'update', 'delete'],
            'purchasing.goods_receipts'  => ['view', 'create', 'update', 'delete'],
            'crm.customers'              => ['view', 'create', 'update', 'delete'],
            'sales.channels'             => ['view', 'create', 'update', 'delete', 'sync'],
            'sales.orders'               => ['view', 'create', 'update', 'delete', 'fulfill'],
            'sales.fulfillments'         => ['view', 'create', 'update', 'delete'],
        ],

        'warehouse-manager' => [
            'inventory.warehouses' => ['view', 'create', 'update'],
            'inventory.categories' => ['view'],
            'inventory.units'      => ['view'],
            'inventory.products'   => ['view'],
            'inventory.stock'      => ['view', 'adjust', 'receive', 'count'],
            'purchasing.goods_receipts' => ['view', 'create'],
        ],

        'purchasing' => [
            'inventory.warehouses'       => ['view'],
            'inventory.categories'       => ['view'],
            'inventory.units'            => ['view'],
            'inventory.products'         => ['view'],
            'purchasing.suppliers'       => ['view', 'create', 'update', 'delete'],
            'purchasing.purchase_orders' => ['view', 'create', 'update', 'delete'],
            'purchasing.goods_receipts'  => ['view', 'create', 'update', 'delete'],
        ],

        'sales' => [
            'inventory.categories' => ['view'],
            'inventory.products'   => ['view'],
            'crm.customers'        => ['view', 'create', 'update', 'delete'],
            'sales.channels'       => ['view'],
            'sales.orders'         => ['view', 'create', 'update', 'fulfill'],
            'sales.fulfillments'   => ['view', 'create', 'update'],
        ],

        'inventory-operator' => [
            'inventory.warehouses' => ['view'],
            'inventory.categories' => ['view'],
            'inventory.units'      => ['view'],
            'inventory.products'   => ['view'],
            'inventory.stock'      => ['view', 'adjust', 'receive', 'count'],
            'purchasing.goods_receipts' => ['view'],
        ],

        'viewer' => [
            'iam.users'                  => ['view'],
            'organization.companies'     => ['view'],
            'organization.branches'      => ['view'],
            'inventory.warehouses'       => ['view'],
            'inventory.categories'       => ['view'],
            'inventory.units'            => ['view'],
            'inventory.products'         => ['view'],
            'inventory.stock'            => ['view'],
            'purchasing.suppliers'       => ['view'],
            'purchasing.purchase_orders' => ['view'],
            'purchasing.goods_receipts'  => ['view'],
            'crm.customers'              => ['view'],
            'sales.channels'             => ['view'],
            'sales.orders'               => ['view'],
            'sales.fulfillments'         => ['view'],
        ],

    ],

];
