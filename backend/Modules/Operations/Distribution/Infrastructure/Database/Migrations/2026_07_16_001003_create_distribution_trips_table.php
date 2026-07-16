<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_trips', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->uuid('preparation_wave_id');
            $table->foreign('preparation_wave_id')->references('id')->on('preparation_waves')->cascadeOnDelete();
            $table->foreignId('distribution_zone_id')->nullable()->constrained('distribution_zones')->nullOnDelete();
            $table->string('trip_number', 20);
            $table->string('name', 100);
            $table->enum('type', ['company_vehicle', 'personal_vehicle', 'external_carrier'])->default('company_vehicle');
            $table->unsignedSmallInteger('capacity')->default(60);
            $table->foreignId('fleet_vehicle_id')->nullable()->constrained('fleet_vehicles')->nullOnDelete();
            $table->foreignId('fleet_driver_id')->nullable()->constrained('fleet_drivers')->nullOnDelete();
            $table->foreignId('external_carrier_id')->nullable()->constrained('external_carriers')->nullOnDelete();
            $table->string('driver_name', 100)->nullable();
            $table->string('driver_phone', 20)->nullable();
            $table->enum('status', ['planning', 'loading', 'dispatched', 'completed', 'cancelled'])->default('planning');
            $table->unsignedInteger('orders_count')->default(0);
            $table->decimal('collection_amount', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['preparation_wave_id', 'trip_number']);
            $table->index(['preparation_wave_id', 'distribution_zone_id', 'status']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_trips');
    }
};
