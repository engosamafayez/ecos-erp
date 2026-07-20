<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_loading_manifests')) {
            return;
        }

        Schema::create('distribution_loading_manifests', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('distribution_trip_id');
            $table->uuid('preparation_wave_id');
            $table->unsignedBigInteger('company_id');
            $table->string('status', 30)->default('pending');
            $table->unsignedSmallInteger('total_products')->default(0);
            $table->unsignedSmallInteger('confirmed_products')->default(0);
            $table->unsignedSmallInteger('shortage_products')->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('warehouse_user_id')->nullable();
            $table->unsignedBigInteger('approved_by');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('distribution_trip_id')->references('id')->on('distribution_trips')->cascadeOnDelete();
            $table->foreign('preparation_wave_id')->references('id')->on('preparation_waves');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('approved_by')->references('id')->on('users');
            $table->foreign('warehouse_user_id')->references('id')->on('users');

            $table->unique('distribution_trip_id');
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_loading_manifests');
    }
};
