<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_events', function ($table) {
            $table->string('actor_role', 100)->nullable()->after('actor_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_events', function ($table) {
            $table->dropColumn('actor_role');
        });
    }
};
