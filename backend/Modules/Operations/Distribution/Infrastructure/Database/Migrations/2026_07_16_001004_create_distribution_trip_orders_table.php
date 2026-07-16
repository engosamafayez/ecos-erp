<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_trip_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('distribution_trip_id');
            $table->foreign('distribution_trip_id')->references('id')->on('distribution_trips')->cascadeOnDelete();
            $table->uuid('order_id');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->string('zone_code_snapshot', 20)->nullable();
            $table->string('governorate_snapshot', 100)->nullable();
            $table->enum('assignment_type', ['auto', 'manual'])->default('auto');
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->useCurrent();

            // Each order can only be assigned to one trip
            $table->unique('order_id');
            $table->index('distribution_trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_trip_orders');
    }
};
