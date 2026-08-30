<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-INB-07 — one inbound receipt line must own at most ONE FIFO layer.
 *
 * `inventory_receipt_layers` had no uniqueness guarantee at all (only the primary key), so
 * duplicate-layer protection lived entirely in application code. This adds the database-level
 * backstop the audit asked for.
 *
 * THE CANONICAL IDENTITY IS `goods_receipt_line_id`, and it is exact, not heuristic. Seven code
 * paths create receipt layers; six of them — count approval, manual stock, transfer,
 * disassembly, manufacturing execution and customer-return receipt — write
 * `goods_receipt_line_id => null` explicitly. The ONLY writer of a non-null value is
 * `CreateReceiptLayersAction` on the Goods Receipt path, where exactly one layer per receipt
 * line is the intended invariant. Nothing is matched by quantity, price, date or similarity.
 *
 * WHY A NULLABLE UNIQUE INDEX IS THE RIGHT SHAPE. MySQL permits unlimited NULLs in a unique
 * index (verified on MySQL 8.4.10: three NULL rows coexist while a genuine duplicate is
 * rejected with errno 1062). So this constrains receipt-sourced layers precisely and stays
 * completely inert for the six null-writing paths and for Mode 3 invoice-sourced layers.
 *
 * KNOWN AND DELIBERATE GAP. Invoice-sourced layers carry `goods_receipt_line_id = null`, so
 * they are NOT covered by this index. No canonical per-line identity column exists for them —
 * the layer table has no `supplier_invoice_line_id` — and inventing one would be new schema
 * beyond this repair's minimum, while keying on anything else would be exactly the fuzzy
 * matching the contract forbids. Those layers are protected by the shared canonical inbound
 * row lock instead (C-1). Recorded rather than papered over.
 *
 * SAFETY. Additive and idempotent: it adds one index, alters no column, drops nothing and
 * rewrites no data. Existing-duplicate check before writing it: `ecos_dev` held 2 layers with
 * 0 non-null `goods_receipt_line_id`; `ecos_dev_test` held 0 rows. Zero duplicates in either.
 * The guard below repeats that check at run time and refuses rather than destroying anything.
 */
return new class extends Migration
{
    private const INDEX = 'irl_goods_receipt_line_unique';

    public function up(): void
    {
        if (! Schema::hasTable('inventory_receipt_layers')) {
            return;
        }

        // Idempotent: re-running must not fail on an already-created index.
        if ($this->indexExists()) {
            return;
        }

        // Fail loudly rather than silently destroying history. If real duplicates ever exist
        // they are a data-integrity incident that a migration must not resolve by itself.
        $duplicates = DB::table('inventory_receipt_layers')
            ->select('goods_receipt_line_id')
            ->whereNotNull('goods_receipt_line_id')
            ->groupBy('goods_receipt_line_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('goods_receipt_line_id')
            ->all();

        if ($duplicates !== []) {
            throw new RuntimeException(
                'D-INB-07: inventory_receipt_layers already contains duplicate goods_receipt_line_id values ('
                .count($duplicates).' affected). Refusing to add the unique index — these rows must be '
                .'reviewed by hand, not merged or deleted automatically. Sample: '
                .implode(', ', array_slice($duplicates, 0, 5)),
            );
        }

        // DB::statement rather than Blueprint: keeps the statement explicit and MySQL-portable.
        DB::statement(
            'ALTER TABLE `inventory_receipt_layers` ADD UNIQUE INDEX `'.self::INDEX.'` (`goods_receipt_line_id`)',
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('inventory_receipt_layers') || ! $this->indexExists()) {
            return;
        }

        DB::statement('ALTER TABLE `inventory_receipt_layers` DROP INDEX `'.self::INDEX.'`');
    }

    private function indexExists(): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'inventory_receipt_layers')
            ->where('INDEX_NAME', self::INDEX)
            ->exists();
    }
};
