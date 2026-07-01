<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cart_id')->unique();
            $table->uuid('session_id');
            $table->uuid('shift_id');
            $table->uuid('terminal_id');
            $table->uuid('cashier_id');
            $table->string('status', 50)->default('pending');
            $table->char('currency', 3);
            $table->jsonb('cart_total');
            $table->jsonb('tenders')->default('[]');
            $table->jsonb('amount_tendered');
            $table->jsonb('change_due');
            $table->timestamp('captured_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index('shift_id');
            $table->index('terminal_id');
            $table->index('cashier_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payments');
    }
};
