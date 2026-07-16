<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('name_en', 100);
            $table->string('name_ar', 100)->nullable();
            $table->string('phone', 20);
            $table->string('national_id', 20)->nullable();
            $table->string('license_type', 10)->nullable();
            $table->date('license_expiry')->nullable();
            $table->enum('status', ['available', 'on_trip', 'off_duty', 'inactive'])->default('available');
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status', 'is_active']);
            $table->unique(['company_id', 'national_id'], 'fleet_drivers_company_national_id_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_drivers');
    }
};
