<?php

declare(strict_types=1);

/**
 * ECOS ERP — Centralised Permission Registry
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │  Naming convention: {module}.{action}                                    │
 * │  e.g. "products.view", "orders.fulfill", "roles.assign"                 │
 * │                                                                          │
 * │  Always reference permissions via this file to avoid hardcoded strings:  │
 * │    config('permissions.modules.products')  → ['view','create',...]       │
 * │    config('permissions.all')              → flat list of all names       │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
return [

    // ── Structured permission registry (module → actions) ─────────────────────
    'modules' => [
        // IAM
        'users'           => ['view', 'create', 'update', 'delete'],
        'roles'           => ['view', 'create', 'update', 'delete', 'assign'],

        // Organization
        'companies'       => ['view', 'create', 'update', 'delete'],
        'branches'        => ['view', 'create', 'update', 'delete'],

        // Master Data
        'warehouses'      => ['view', 'create', 'update', 'delete'],
        'categories'      => ['view', 'create', 'update', 'delete'],
        'units'           => ['view', 'create', 'update', 'delete'],

        // Inventory
        'products'        => ['view', 'create', 'update', 'delete'],
        'inventory'       => ['view', 'adjust', 'receive', 'count'],

        // Purchasing
        'suppliers'       => ['view', 'create', 'update', 'delete'],
        'purchase_orders' => ['view', 'create', 'update', 'delete'],
        'goods_receipts'  => ['view', 'create', 'update', 'delete'],

        // Sales & Commerce
        'customers'       => ['view', 'create', 'update', 'delete'],
        'channels'        => ['view', 'create', 'update', 'delete', 'sync'],
        'orders'          => ['view', 'create', 'update', 'delete', 'fulfill'],
        'fulfillments'    => ['view', 'create', 'update', 'delete'],
    ],

    // ── Role definitions ──────────────────────────────────────────────────────
    'roles' => [
        'super-admin'        => 'Super Admin',
        'company-admin'      => 'Company Admin',
        'warehouse-manager'  => 'Warehouse Manager',
        'purchasing'         => 'Purchasing',
        'sales'              => 'Sales',
        'inventory-operator' => 'Inventory Operator',
        'viewer'             => 'Viewer',
    ],

    // ── Role → permission grants (used by RbacSeeder) ─────────────────────────
    //
    // '*' means all actions for that module (expanded by the seeder).
    // Super Admin has no entry here — their bypass lives in Gate::before().
    //
    'role_permissions' => [

        'company-admin' => [
            'users'           => ['view', 'create', 'update'],
            'roles'           => ['view', 'assign'],
            'companies'       => ['view', 'create', 'update'],
            'branches'        => ['view', 'create', 'update', 'delete'],
            'warehouses'      => ['view', 'create', 'update', 'delete'],
            'categories'      => ['view', 'create', 'update', 'delete'],
            'units'           => ['view', 'create', 'update', 'delete'],
            'products'        => ['view', 'create', 'update', 'delete'],
            'inventory'       => ['view', 'adjust', 'receive', 'count'],
            'suppliers'       => ['view', 'create', 'update', 'delete'],
            'purchase_orders' => ['view', 'create', 'update', 'delete'],
            'goods_receipts'  => ['view', 'create', 'update', 'delete'],
            'customers'       => ['view', 'create', 'update', 'delete'],
            'channels'        => ['view', 'create', 'update', 'delete', 'sync'],
            'orders'          => ['view', 'create', 'update', 'delete', 'fulfill'],
            'fulfillments'    => ['view', 'create', 'update', 'delete'],
        ],

        'warehouse-manager' => [
            'warehouses'     => ['view', 'create', 'update'],
            'categories'     => ['view'],
            'units'          => ['view'],
            'products'       => ['view'],
            'inventory'      => ['view', 'adjust', 'receive', 'count'],
            'goods_receipts' => ['view', 'create'],
        ],

        'purchasing' => [
            'warehouses'      => ['view'],
            'categories'      => ['view'],
            'units'           => ['view'],
            'products'        => ['view'],
            'suppliers'       => ['view', 'create', 'update', 'delete'],
            'purchase_orders' => ['view', 'create', 'update', 'delete'],
            'goods_receipts'  => ['view', 'create', 'update', 'delete'],
        ],

        'sales' => [
            'categories'   => ['view'],
            'products'     => ['view'],
            'customers'    => ['view', 'create', 'update', 'delete'],
            'channels'     => ['view'],
            'orders'       => ['view', 'create', 'update', 'fulfill'],
            'fulfillments' => ['view', 'create', 'update'],
        ],

        'inventory-operator' => [
            'warehouses'     => ['view'],
            'categories'     => ['view'],
            'units'          => ['view'],
            'products'       => ['view'],
            'inventory'      => ['view', 'adjust', 'receive', 'count'],
            'goods_receipts' => ['view'],
        ],

        'viewer' => [
            'users'           => ['view'],
            'companies'       => ['view'],
            'branches'        => ['view'],
            'warehouses'      => ['view'],
            'categories'      => ['view'],
            'units'           => ['view'],
            'products'        => ['view'],
            'inventory'       => ['view'],
            'suppliers'       => ['view'],
            'purchase_orders' => ['view'],
            'goods_receipts'  => ['view'],
            'customers'       => ['view'],
            'channels'        => ['view'],
            'orders'          => ['view'],
            'fulfillments'    => ['view'],
        ],
    ],

];
