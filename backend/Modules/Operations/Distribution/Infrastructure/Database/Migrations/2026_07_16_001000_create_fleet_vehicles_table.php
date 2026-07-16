<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('plate_number', 20)->unique();
            $table->enum('type', ['van', 'truck', 'motorcycle', 'pickup', 'car'])->default('van');
            $table->string('make', 50)->nullable();
            $table->string('model', 50)->nullable();
            $table->smallInteger('year')->nullable();
            $table->unsignedSmallInteger('capacity_orders')->default(60);
            $table->enum('status', ['available', 'in_use', 'maintenance', 'retired'])->default('available');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_vehicles');
    }
};
