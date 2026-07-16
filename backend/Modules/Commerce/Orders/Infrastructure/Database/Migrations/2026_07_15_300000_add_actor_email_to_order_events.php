<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_events', function ($table) {
            $table->string('actor_email', 255)->nullable()->after('actor_role');
        });
    }

    public function down(): void
    {
        Schema::table('order_events', function ($table) {
            $table->dropColumn('actor_email');
        });
    }
};
