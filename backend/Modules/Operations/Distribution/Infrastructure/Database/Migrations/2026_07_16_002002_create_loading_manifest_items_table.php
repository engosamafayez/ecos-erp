<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('distribution_loading_manifest_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('loading_manifest_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name', 200);
            $table->string('product_sku', 100)->nullable();
            $table->decimal('required_qty', 14, 3);
            $table->string('unit', 30)->default('unit');
            $table->decimal('loaded_qty', 14, 3)->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('shortage_qty', 14, 3)->nullable();
            $table->string('shortage_resolution', 50)->nullable();
            $table->text('shortage_notes')->nullable();
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('loading_manifest_id')->references('id')->on('distribution_loading_manifests')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('confirmed_by')->references('id')->on('users');

            $table->index(['loading_manifest_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('distribution_loading_manifest_items');
    }
};
