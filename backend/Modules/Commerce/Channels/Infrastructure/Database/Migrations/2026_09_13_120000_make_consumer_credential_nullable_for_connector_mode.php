<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2-R1 §6 — an official Connector-mode Channel never
 * requires the merchant/operator to obtain or paste a WooCommerce REST consumer key/secret at
 * all: the Plugin applies every change locally via WooCommerce's own REST controller classes,
 * authenticated by the dedicated `connector_token` instead. `consumer_key`/`consumer_secret`
 * remain required for the pre-existing legacy direct-REST path (a Channel that has never
 * completed Connector pairing) but must be nullable for a pairing that never collects them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channel_credentials', function (Blueprint $table): void {
            $table->string('consumer_key')->nullable()->change();
            $table->string('consumer_secret')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('channel_credentials', function (Blueprint $table): void {
            $table->string('consumer_key')->nullable(false)->change();
            $table->string('consumer_secret')->nullable(false)->change();
        });
    }
};
