<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('distribution_wave_exceptions')) {
            return;
        }

        Schema::create('distribution_wave_exceptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('preparation_wave_id');
            $table->uuid('order_id');
            $table->uuid('distribution_trip_id')->nullable();
            $table->string('reason', 50)->default('supervisor_return');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('returned_by');
            $table->timestamp('returned_at')->useCurrent();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolution', 30)->nullable();

            $table->foreign('preparation_wave_id')->references('id')->on('preparation_waves')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('returned_by')->references('id')->on('users');
            $table->foreign('resolved_by')->references('id')->on('users');

            $table->index(['preparation_wave_id', 'resolved_at']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_wave_exceptions');
    }
};
