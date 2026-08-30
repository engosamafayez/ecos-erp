<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Logistics\Distribution\Domain\Models\DeliveryProof;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;

/**
 * Secure delivery proof-of-delivery upload (TASK-DELIVERY-POD-SECURE-UPLOAD-001).
 *
 * Mirrors the proven payment-proof upload contract (UploadPaymentProofAction):
 *   • real uploaded files only — NEVER a client-supplied path string;
 *   • stored on the PRIVATE `local` disk (no public URL);
 *   • the storage path is a SERVER-generated ULID, never the client filename;
 *   • MIME is sniffed from the real content (getMimeType), size from the real file;
 *   • tenancy: the storage folder is scoped by the owning company, resolved from
 *     `stop → trip → company_id` (the proofs table has no company_id of its own).
 *
 * It keeps DeliveryProof as the Distribution POD record (CTO boundary — it never
 * writes payment_proofs). It records ONLY proof evidence: no payment, inventory,
 * custody or delivery-quantity behaviour.
 */
final class UploadDeliveryProofAction
{
    /** Private disk — POD evidence is served only through the tenant-scoped download. */
    private const DISK = 'local';

    /**
     * @param  list<UploadedFile>  $photoFiles
     */
    public function execute(
        DeliveryStop $stop,
        ?UploadedFile $signature,
        array $photoFiles,
        ?string $notes,
        ?int $actorId,
    ): DeliveryProof {
        // Tenancy path: the proofs table carries no company_id, so the owning company
        // is reached through the (already ownership-checked) stop's trip.
        $companyId = (string) $stop->trip->company_id;

        $signaturePath = null;
        $signatureMime = null;
        $signatureSize = null;

        if ($signature !== null) {
            [$signaturePath, $signatureMime, $signatureSize] = $this->store($signature, $companyId);
        }

        $photos = [];
        foreach ($photoFiles as $photo) {
            [$path, $mime, $size] = $this->store($photo, $companyId);
            $photos[] = [
                'disk' => self::DISK,
                'path' => $path,
                'mime_type' => $mime,
                'size_bytes' => $size,
                'original_filename' => $photo->getClientOriginalName(),
            ];
        }

        return $stop->proof()->create([
            'storage_disk' => self::DISK,
            'signature_path' => $signaturePath,
            'signature_mime' => $signatureMime,
            'signature_size' => $signatureSize,
            'photos' => $photos,
            'notes' => $notes,
            'captured_at' => now(),
            'captured_by' => $actorId,
        ]);
    }

    /**
     * Store one uploaded file on the private disk under a server-generated path.
     *
     * @return array{0: string, 1: string|null, 2: int|false}
     */
    private function store(UploadedFile $file, string $companyId): array
    {
        $ext = strtolower($file->getClientOriginalExtension() ?: ($file->extension() ?: 'bin'));
        $path = 'delivery-proofs/'.$companyId.'/'.Str::ulid().'.'.$ext;

        Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

        return [$path, $file->getMimeType(), $file->getSize()];
    }
}
