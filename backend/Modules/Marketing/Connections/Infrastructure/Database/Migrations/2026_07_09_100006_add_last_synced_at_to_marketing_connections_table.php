<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_connections', function (Blueprint $table): void {
            $table->timestamp('last_synced_at')->nullable()->after('last_validated_at');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_connections', function (Blueprint $table): void {
            $table->dropColumn('last_synced_at');
        });
    }
};
