<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ECOS-V1.1-CRM-04-SECURE-SELF-SERVICE-BACKEND-IMPLEMENTATION-019 §5/§33 — the ONE table
 * the architecture task (018) already named and this task's own approved business decision #3
 * confirms: guest secure tracking is the whole V1 access model (no Customer password auth).
 *
 * `order_id` NOT NULL by default in V1 (approved decision + §26 default: "ORDER-SCOPED TOKEN" —
 * a verification against one Order must never silently escalate into every historical Order
 * belonging to that Customer). The column stays nullable at the schema level only because a
 * future, genuinely-justified Customer-wide verification authority may need it — nothing in this
 * task issues a token with a null order_id.
 *
 * `token_hash` only — the raw opaque token is returned to the caller exactly once (at issuance)
 * and never persisted, mirroring `customer_verification_challenges.code_hash`. Fixed 7-day
 * expiry, no sliding extension (`last_used_at` is informational only, never read to extend
 * `expires_at`). `revoked_at` makes immediate invalidation possible without waiting for expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_tracking_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->uuid('company_id');
            $table->uuid('brand_id')->nullable();
            $table->uuid('order_id')->nullable();
            $table->string('channel', 20);
            $table->string('token_hash')->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'company_id'], 'ctt_customer_company_idx');
            $table->index(['order_id'], 'ctt_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tracking_tokens');
    }
};
