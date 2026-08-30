<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Application\Actions;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementCategory;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementStatus;
use Modules\Logistics\Distribution\Domain\Models\DriverTripMovement;

/**
 * Records ONE driver trip operational movement (TASK-DRIVER-APP-OPERATIONAL-FLOW-VNEXT-001 §34/§36).
 *
 * The sole writer of a driver-created movement. It always creates the movement Pending (§35 — the
 * driver never self-approves), derives the cash direction from the category (§32/§57), and stores
 * an OPTIONAL receipt on the private disk under a server-generated path — mirroring the certified
 * UploadDeliveryProofAction (no client-named path, private disk only). Company / driver / trip are
 * supplied by the controller from the authenticated driver's current custody, never the client.
 */
final class RecordDriverTripMovementAction
{
    private const DISK = 'local';

    public function execute(
        string $companyId,
        int $driverId,
        int $tripId,
        DriverTripMovementCategory $category,
        float $amount,
        ?string $note,
        DateTimeInterface $occurredAt,
        ?UploadedFile $receipt,
        string $actorId,
    ): DriverTripMovement {
        return DB::transaction(function () use (
            $companyId,
            $driverId,
            $tripId,
            $category,
            $amount,
            $note,
            $occurredAt,
            $receipt,
            $actorId,
        ): DriverTripMovement {
            $disk = null;
            $receiptPath = null;
            $receiptMime = null;
            $receiptSize = null;

            if ($receipt !== null) {
                // Server-generated private path — the client filename is never used or returned.
                $ext = strtolower($receipt->getClientOriginalExtension() ?: 'bin');
                $receiptPath = 'trip-expenses/'.$companyId.'/'.Str::ulid().'.'.$ext;
                Storage::disk(self::DISK)->put($receiptPath, file_get_contents($receipt->getRealPath()));
                $disk = self::DISK;
                $receiptMime = $receipt->getMimeType();
                $receiptSize = $receipt->getSize();
            }

            return DriverTripMovement::create([
                'company_id' => $companyId,
                'driver_id' => $driverId,
                'trip_id' => $tripId,
                'category' => $category->value,
                'direction' => $category->direction()->value,
                'amount' => round($amount, 2),
                'note' => $note,
                'occurred_at' => $occurredAt,
                'status' => DriverTripMovementStatus::Pending->value,
                'storage_disk' => $disk,
                'receipt_path' => $receiptPath,
                'receipt_mime' => $receiptMime,
                'receipt_size' => $receiptSize,
                'created_by' => $actorId,
                'updated_by' => $actorId,
            ]);
        });
    }
}
