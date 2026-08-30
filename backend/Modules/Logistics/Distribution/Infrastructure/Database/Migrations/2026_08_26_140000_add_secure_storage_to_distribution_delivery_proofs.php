<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-DELIVERY-POD-SECURE-UPLOAD-001 — server-artifact identity for delivery proof.
 *
 * The original table stored client-supplied `signature_path` / `photos` STRINGS
 * verbatim, so a "proof" was just an unverified claim. The secure upload path
 * (UploadDeliveryProofAction) stores real files on a PRIVATE disk under a
 * server-generated ULID path; these columns record which disk holds the signature
 * and its sniffed MIME/size so the file can be served back through a tenant-scoped
 * download endpoint.
 *
 * ADDITIVE + BACKWARD-COMPATIBLE: all columns are nullable. Legacy rows keep
 * `storage_disk = null` and are simply treated as legacy (not served as secure).
 * Photos are now stored in the existing `photos` JSON column as structured entries
 * ({disk, path, mime_type, size_bytes, original_filename}) — no schema change needed
 * for them. The DeliveryProof record itself is unchanged in identity (it stays
 * Distribution's own POD record — CTO boundary), only its ingestion becomes secure.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('distribution_delivery_proofs')) {
            return;
        }

        Schema::table('distribution_delivery_proofs', function (Blueprint $table): void {
            if (! Schema::hasColumn('distribution_delivery_proofs', 'storage_disk')) {
                $table->string('storage_disk', 50)->nullable()->after('stop_id');
            }
            if (! Schema::hasColumn('distribution_delivery_proofs', 'signature_mime')) {
                $table->string('signature_mime', 191)->nullable()->after('signature_path');
            }
            if (! Schema::hasColumn('distribution_delivery_proofs', 'signature_size')) {
                $table->unsignedBigInteger('signature_size')->nullable()->after('signature_mime');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('distribution_delivery_proofs')) {
            return;
        }

        Schema::table('distribution_delivery_proofs', function (Blueprint $table): void {
            foreach (['storage_disk', 'signature_mime', 'signature_size'] as $column) {
                if (Schema::hasColumn('distribution_delivery_proofs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
