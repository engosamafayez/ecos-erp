<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 — the ECOS WooCommerce Connector plugin pairs to a
 * Channel via a short-lived, single-use, hashed pairing code, then authenticates itself with a
 * dedicated `connector_token` (see the sibling migration on channel_credentials) — never the
 * Woo REST consumer_key/secret. Connection health is derived (see Channel::connectorHealth())
 * from `connector_last_heartbeat_at` and `connector_disconnected_at`, not a new duplicated
 * status enum: a channel that has never been paired has both null; a live connector updates
 * the heartbeat on its own schedule; an explicit deactivation notice sets
 * `connector_disconnected_at`; a site that goes dark without notifying simply stops
 * refreshing the heartbeat, which is read as staleness rather than a false "healthy forever".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->string('pairing_code_hash')->nullable()->after('connection_status');
            $table->timestamp('pairing_code_expires_at')->nullable()->after('pairing_code_hash');
            $table->timestamp('connector_last_heartbeat_at')->nullable()->after('pairing_code_expires_at');
            $table->timestamp('connector_disconnected_at')->nullable()->after('connector_last_heartbeat_at');
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn([
                'pairing_code_hash',
                'pairing_code_expires_at',
                'connector_last_heartbeat_at',
                'connector_disconnected_at',
            ]);
        });
    }
};
