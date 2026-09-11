<?php

declare(strict_types=1);

namespace Tests\Unit\Manufacturing;

use Modules\Manufacturing\BillsOfMaterials\Domain\ValueObjects\RecipeComponent;
use Modules\Manufacturing\BillsOfMaterials\Domain\ValueObjects\RecipeSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-V1-REMEDIATION-COLLABORATION-INTEGRITY-035D-R1 §4 — recipe snapshot hash stability.
 *
 * No database, no Laravel boot, no infrastructure — RecipeSnapshot/RecipeComponent are plain
 * readonly value objects, same discipline as ManufacturingPlannerTest in this directory.
 *
 * The hash every consumer computes (ManufacturingPlanner::hashSnapshot(),
 * ExecutionPipeline::validateSnapshotHash(), DisassemblyExecutor) must be over
 * RecipeSnapshot::semanticFingerprint(), not toArray() — toArray() still includes the volatile
 * resolved_at wall-clock value (kept there deliberately, for audit/display), which would
 * otherwise make the hash differ on every single resolution even when nothing about the recipe
 * actually changed.
 */
final class RecipeSnapshotHashStabilityTest extends TestCase
{
    private function component(float $quantity = 2.0): RecipeComponent
    {
        return new RecipeComponent(
            component_id: 'comp-1',
            sku: 'SKU-1',
            name: 'Flour',
            unit_id: 'unit-1',
            unit_name: 'Kilogram',
            unit_symbol: 'kg',
            quantity: $quantity,
            allow_negative_stock: false,
        );
    }

    private function snapshot(string $resolvedAt, float $quantity = 2.0): RecipeSnapshot
    {
        return new RecipeSnapshot(
            recipe_id: 'recipe-1',
            bom_number: 'BOM-1',
            version: '1.0',
            bom_version_number: 1,
            product_id: 'product-1',
            product_sku: 'PROD-1',
            product_name: 'Bread',
            components: [$this->component($quantity)],
            resolved_at: $resolvedAt,
        );
    }

    private function hash(RecipeSnapshot $snapshot): string
    {
        return hash('sha256', json_encode($snapshot->semanticFingerprint(), JSON_THROW_ON_ERROR));
    }

    public function test_the_same_semantic_recipe_hashes_identically_regardless_of_resolved_at(): void
    {
        $first = $this->snapshot('2026-01-01T00:00:00+00:00');
        $second = $this->snapshot('2026-06-15T12:34:56+00:00');

        self::assertSame($this->hash($first), $this->hash($second));
    }

    public function test_a_changed_component_quantity_changes_the_hash(): void
    {
        $original = $this->snapshot('2026-01-01T00:00:00+00:00', quantity: 2.0);
        $changed = $this->snapshot('2026-01-01T00:00:00+00:00', quantity: 3.0);

        self::assertNotSame($this->hash($original), $this->hash($changed));
    }

    public function test_resolved_at_is_still_present_in_toarray_for_audit_data(): void
    {
        $snapshot = $this->snapshot('2026-01-01T00:00:00+00:00');

        self::assertArrayHasKey('resolved_at', $snapshot->toArray());
        self::assertArrayNotHasKey('resolved_at', $snapshot->semanticFingerprint());
    }
}
