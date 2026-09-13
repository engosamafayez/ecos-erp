<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 — the WooCommerce Connector plugin's OWN
 * persistent authentication secret, issued once during pairing (ExchangePairingCodeAction) and
 * used thereafter for every plugin→ECOS call (status/heartbeat/repair/deactivation).
 * Deliberately a separate column from consumer_key/consumer_secret (which remain exclusively
 * ECOS→Woo REST credentials, entered by the operator and never sent to the plugin) — extending
 * this existing, already-encrypted-at-rest table rather than standing up a new credential
 * store, per the smallest-additive-storage instruction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_credentials', function (Blueprint $table): void {
            $table->text('connector_token')->nullable()->after('consumer_secret');
        });
    }

    public function down(): void
    {
        Schema::table('channel_credentials', function (Blueprint $table): void {
            $table->dropColumn('connector_token');
        });
    }
};
