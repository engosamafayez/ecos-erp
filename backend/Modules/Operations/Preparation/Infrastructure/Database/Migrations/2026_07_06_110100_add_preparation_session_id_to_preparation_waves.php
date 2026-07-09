<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preparation_waves', function (Blueprint $table): void {
            $table->uuid('preparation_session_id')->nullable()->after('id');
            $table->index('preparation_session_id', 'idx_preparation_waves_session_id');
        });
    }

    public function down(): void
    {
        Schema::table('preparation_waves', function (Blueprint $table): void {
            $table->dropIndex('idx_preparation_waves_session_id');
            $table->dropColumn('preparation_session_id');
        });
    }
};
