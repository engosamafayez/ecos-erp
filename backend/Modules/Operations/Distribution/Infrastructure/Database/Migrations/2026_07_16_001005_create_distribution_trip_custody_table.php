<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_trip_custody', function (Blueprint $table) {
            $table->id();
            $table->uuid('distribution_trip_id');
            $table->foreign('distribution_trip_id')->references('id')->on('distribution_trips')->cascadeOnDelete();
            $table->enum('item_type', [
                'cash_float', 'pos_device', 'ice_boxes', 'ice_packs',
                'thermal_bags', 'delivery_bags', 'other',
            ]);
            $table->string('description', 200)->nullable();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('distribution_trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_trip_custody');
    }
};
