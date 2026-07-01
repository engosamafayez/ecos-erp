<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_carts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Cross-module UUID references — no FK constraints (no cross-module FKs per ADR-POS-001)
            $table->uuid('session_id');
            $table->uuid('shift_id');
            $table->uuid('terminal_id');
            $table->uuid('cashier_id');
            $table->uuid('customer_id')->nullable();

            $table->string('status', 50)->default('active');
            $table->char('currency', 3);

            // Lines stored as JSONB array — each element matches CartLine::toArray()
            $table->jsonb('lines')->default('[]');

            // Money fields as JSONB {amount, currency} — always present once the cart is opened
            $table->jsonb('subtotal');
            $table->jsonb('discount_total');
            $table->jsonb('total');

            // Optional order-level discount
            $table->string('order_discount_type', 50)->nullable();
            $table->string('order_discount_value', 50)->nullable();

            // Assigned at complete(); unique across all completed carts
            $table->string('receipt_number', 100)->nullable()->unique();

            $table->text('notes')->nullable();

            $table->timestamp('held_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expired_at')->nullable();

            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index('session_id');
            $table->index('shift_id');
            $table->index('terminal_id');
            $table->index('cashier_id');
            $table->index('status');
            $table->index('customer_id');
        });

        // Enforce: at most one cart in the Paying state per session at a time.
        // This prevents two concurrent payment screens for the same session.
        DB::statement("
            CREATE UNIQUE INDEX pos_carts_one_paying_per_session
            ON pos_carts (session_id)
            WHERE status = 'paying'
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_carts');
    }
};
