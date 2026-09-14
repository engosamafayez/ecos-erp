<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1.1-CRM-04-SECURE-SELF-SERVICE-BACKEND-IMPLEMENTATION-019 §2/§4/§5 — proof of
 * control for guest order tracking. No existing verification-challenge persistence authority
 * exists anywhere in this codebase (Laravel's own `password_reset_tokens` is keyed by email
 * alone, has no company/Brand/order scope, and is deeply tied to the password-reset broker's own
 * semantics — reusing it would conflate two different security contracts, not avoid a second
 * one). This is the smallest table that can hold a hashed, time-boxed, attempt-bounded,
 * single-use OTP scoped to the exact customer/company/Brand/order it was issued for.
 *
 * The OTP itself is NEVER stored in plaintext (`code_hash` only, hashed the same way a password
 * would be). `attempts`/`max_attempts` bound guessing. `consumed_at` makes verification
 * single-use (a consumed or expired challenge can never be replayed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_verification_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('company_id');
            $table->uuid('brand_id')->nullable();
            $table->uuid('order_id')->nullable();
            // 'email' only in V1 — no SMS/WhatsApp transactional authority exists in this
            // codebase (see the Task 1 report's VERIFICATION DELIVERY section).
            $table->string('channel', 20);
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(5);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'company_id'], 'cvc_customer_company_idx');
            $table->index('expires_at', 'cvc_expires_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_verification_challenges');
    }
};
