<?php

declare(strict_types=1);

namespace Modules\Operations\DemandAnalysis\Application\Services;

use Illuminate\Support\Facades\DB;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;

/**
 * Per-product preparation readiness for a wave.
 *
 * OWNER CONTRACT — Missing Material and Preparation Eligibility are TWO DIFFERENT
 * CONCEPTS, and this service is where the second one is decided:
 *
 *   missing_qty  — the REAL physical shortage. Always accurate, never zeroed.
 *                  Owned by MaterialDemandCalculator; Procurement reads it.
 *   readiness    — may preparation proceed despite that shortage? Decided here.
 *
 *   missing_qty > 0  AND allow_negative_stock = true   → READY
 *   missing_qty > 0  AND allow_negative_stock = false  → WAITING_MATERIAL
 *   missing_qty = 0                                    → READY
 *
 * The allow-negative case is the real business scenario where Procurement has already
 * bought the material but the receipt has not been entered into the ERP yet: preparation
 * is permitted, and the buyer still sees the outstanding quantity.
 *
 * THIS IS NOT A SECOND DEMAND ENGINE. It computes no availability of its own — it joins
 * figures already persisted by the material layer to products through the recipe edge
 * MaterialDemandCalculator already derives. Readiness is scoped strictly to the product:
 * it never reads or writes the wave status, the order status, or any reservation.
 */
final class ProductReadinessCalculator
{
    public const READY = 'ready';

    public const WAITING_MATERIAL = 'waiting_material';

    public function __construct(private readonly MaterialDemandCalculator $materials) {}

    /**
     * Readiness for every product in the wave (or only `$affectedProductIds`).
     *
     * @param  list<string>|null  $affectedProductIds
     * @return array<string, array{material_status: string, blocking_materials_count: int}>
     */
    public function calculate(PreparationWave $wave, ?array $affectedProductIds = null): array
    {
        $materialsByProduct = $this->materials->materialsByProduct($wave, $affectedProductIds);

        if ($materialsByProduct === []) {
            return [];
        }

        // The materials that actually BLOCK: physically short AND not drawable on credit.
        // Read from the persisted projection the material layer has just written, so
        // readiness can never disagree with the numbers on screen.
        $blocking = DB::table('wave_material_demand')
            ->where('preparation_wave_id', $wave->id)
            ->where('missing_qty', '>', 0)
            ->where(function ($q): void {
                $q->where('allow_negative', false)->orWhereNull('allow_negative');
            })
            ->pluck('material_id')
            ->map(static fn ($id): string => (string) $id)
            ->flip();

        $readiness = [];

        foreach ($materialsByProduct as $productId => $materialIds) {
            $blockers = 0;

            foreach ($materialIds as $materialId) {
                if ($blocking->has($materialId)) {
                    $blockers++;
                }
            }

            $readiness[$productId] = [
                'material_status' => $blockers > 0 ? self::WAITING_MATERIAL : self::READY,
                'blocking_materials_count' => $blockers,
            ];
        }

        return $readiness;
    }
}
