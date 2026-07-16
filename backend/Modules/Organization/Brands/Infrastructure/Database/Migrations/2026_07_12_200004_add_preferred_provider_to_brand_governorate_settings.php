<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_governorate_settings', function (Blueprint $table): void {
            $table->string('preferred_provider', 50)->nullable()
                ->after('display_order')
                ->comment('Override brand default provider for this governorate (bosta, mylerz, etc.)');
        });
    }

    public function down(): void
    {
        Schema::table('brand_governorate_settings', function (Blueprint $table): void {
            $table->dropColumn('preferred_provider');
        });
    }
};
