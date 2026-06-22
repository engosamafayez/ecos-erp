<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\IAM\Presentation\Http\Controllers\AuthController;

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
