<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\IAM\Presentation\Http\Controllers\AuthController;
use Modules\Inventory\Products\Presentation\Http\Controllers\ProductController;
use Modules\MasterData\Categories\Presentation\Http\Controllers\CategoryController;
use Modules\MasterData\Units\Presentation\Http\Controllers\UnitController;
use Modules\MasterData\Warehouses\Presentation\Http\Controllers\WarehouseController;
use Modules\Organization\Branches\Presentation\Http\Controllers\BranchController;
use Modules\Organization\Companies\Presentation\Http\Controllers\CompanyController;
use Modules\Purchasing\PurchaseOrders\Presentation\Http\Controllers\PurchaseOrderController;
use Modules\Purchasing\Suppliers\Presentation\Http\Controllers\SupplierController;

/*
|--------------------------------------------------------------------------
| IAM — Authentication routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
    });
});

/*
|--------------------------------------------------------------------------
| Organization — Companies (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('companies', CompanyController::class);
    Route::apiResource('branches', BranchController::class);
});

/*
|--------------------------------------------------------------------------
| Master Data — Warehouses, Categories, Units (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('warehouses', WarehouseController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('units', UnitController::class);
});

/*
|--------------------------------------------------------------------------
| Inventory — Products (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('products', ProductController::class);
});

/*
|--------------------------------------------------------------------------
| Purchasing — Suppliers (protected)
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('suppliers', SupplierController::class);
    Route::apiResource('purchase-orders', PurchaseOrderController::class);
    Route::post('purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve']);
    Route::post('purchase-orders/{purchaseOrder}/cancel', [PurchaseOrderController::class, 'cancel']);
});
