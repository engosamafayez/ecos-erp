<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_sales', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('cart_id')->unique();
            $table->uuid('payment_id')->unique();
            $table->uuid('session_id');
            $table->uuid('shift_id');
            $table->uuid('terminal_id');
            $table->uuid('cashier_id');
            $table->uuid('customer_id')->nullable();
            $table->string('status', 50)->default('pending');
            $table->char('currency', 3);
            $table->string('receipt_number', 100)->unique();
            $table->jsonb('lines')->default('[]');
            $table->jsonb('subtotal');
            $table->jsonb('discount_total');
            $table->jsonb('total');
            $table->jsonb('amount_paid');
            $table->jsonb('change_given');
            $table->jsonb('payment_summaries')->default('[]');
            $table->text('notes')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('voided_reason', 500)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index('shift_id');
            $table->index('terminal_id');
            $table->index('cashier_id');
            $table->index('customer_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sales');
    }
};
