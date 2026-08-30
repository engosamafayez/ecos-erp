<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TASK-ORDER-LIFECYCLE-V3-SUPERSESSION-001 — ADR-042 (Order FSM V3 Canonical).
 *
 * Supersedes the STATUS VOCABULARY installed by
 * 2026_07_22_100000_simplify_order_lifecycle_v3. That migration is deliberately
 * left untouched: it is the historical record of what happened in July 2026.
 *
 *   new         → in_progress   (B1: `new` leaves the canonical lifecycle)
 *   confirmed                   (restored as a canonical state — NOT written here;
 *                                reachable only via the Confirm action)
 *
 * WHY DATA MUST BE NORMALISED BEFORE `NewOrder` LEAVES THE ENUM
 * ------------------------------------------------------------
 * `Order::$casts` maps `status` to `OrderStatus::class`. Eloquent calls
 * `OrderStatus::from()` on every hydration. The moment the `NewOrder` case is
 * deleted, any row still holding 'new' throws
 *   ValueError: "new" is not a valid backing value for enum OrderStatus
 * and that is not cosmetic — it breaks the order list, the order drawer, and
 * every query that eager-loads such a row. Normalisation is therefore a
 * PRECONDITION of the enum change, not a follow-up to it.
 *
 * DELIBERATELY RAW
 * ----------------
 * This migration uses raw SQL and the query builder only. It never references
 * the OrderStatus enum, never touches an Eloquent model, and never triggers the
 * status cast. That is what makes it safe to run with EITHER the old or the new
 * code loaded — see ADR-042 §11 for the required deploy ordering.
 *
 * Idempotent: every statement is a WHERE-guarded UPDATE or a value-set write.
 * Re-running it is a no-op.
 */
return new class extends Migration
{
    /**
     * Legacy → canonical status map (ADR-042 §8).
     *
     * `pending`/`processing`/`preparing`/`review`/`rescheduled`/`completed` were
     * already migrated in July 2026. They are re-applied here defensively because
     * the `orders.status` column carried a DEFAULT of 'pending' (fixed below) —
     * so any INSERT that omitted `status` could still have produced a
     * non-canonical row after the V3 migration ran.
     */
    private const STATUS_MAP = [
        'new'         => 'in_progress',
        'pending'     => 'in_progress',
        'processing'  => 'in_progress',
        'preparing'   => 'in_progress',
        'review'      => 'on_hold',
        'rescheduled' => 'on_hold',
        'completed'   => 'delivered',
    ];

    /** Canonical fulfilment-eligible statuses (ADR-042 §7). */
    private const ELIGIBLE = ['in_progress', 'confirmed'];

    /**
     * Entry-status normalisation for configuration lists (ADR-042 §8).
     *
     * `confirmed` maps to null = REMOVE. It is not an entry status, and because
     * it becomes canonical again a stale row containing it would otherwise be
     * accidentally valid with the wrong meaning.
     */
    private const ENTRY_MAP = [
        'new'         => 'in_progress',
        'pending'     => 'in_progress',
        'processing'  => 'in_progress',
        'preparing'   => 'in_progress',
        'confirmed'   => null,
        'review'      => 'on_hold',
        'rescheduled' => 'on_hold',
        'completed'   => 'delivered',
    ];

    public function up(): void
    {
        // ── Step 1: Normalise order rows ──────────────────────────────────────
        foreach (self::STATUS_MAP as $legacy => $canonical) {
            DB::table('orders')->where('status', $legacy)->update(['status' => $canonical]);
        }

        // ── Step 2: Correct the column default ────────────────────────────────
        // The column defaulted to 'pending' — a value no enum case accepts. Any
        // INSERT omitting `status` produced an unhydratable row.
        if (Schema::hasColumn('orders', 'status')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('status', 255)->nullable(false)->default('in_progress')->change();
            });
        }

        // ── Step 3: Wave Engine eligibility ───────────────────────────────────
        // Left holding ["new","in_progress"]. Removing `new` without this would
        // re-break the Wave Engine exactly as the stale ["confirmed"] value did.
        $this->normaliseEligibility('wave_engine_configurations');

        // ── Step 4: Preparation session policy eligibility ────────────────────
        $this->normaliseEligibility('preparation_session_policies');

        // ── Step 5: Brand source-entry policies ───────────────────────────────
        $this->normaliseBrandEntryPolicies();
    }

    /**
     * Rewrites an `eligible_order_statuses` JSON column to the canonical set.
     *
     * Any row that mentioned `new` or a pre-V3 value is replaced wholesale rather
     * than patched: eligibility is a closed contract (ADR-042 §7), so a partial
     * repair would leave a half-canonical list that is harder to reason about
     * than a known-good one. Rows that are already canonical are left alone so
     * the migration stays idempotent and does not clobber deliberate config.
     */
    private function normaliseEligibility(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'eligible_order_statuses')) {
            return;
        }

        foreach (DB::table($table)->get(['id', 'eligible_order_statuses']) as $row) {
            $current = $this->decodeList($row->eligible_order_statuses);

            if ($current === null) {
                continue;
            }

            $normalised = $this->mapList($current, self::ENTRY_MAP);

            // Any list that carried a legacy value, or that is missing a canonical
            // member, is reset to the canonical pair.
            $needsReset = $normalised !== $current
                || array_diff(self::ELIGIBLE, $normalised) !== []
                || array_diff($normalised, self::ELIGIBLE) !== [];

            if ($needsReset) {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['eligible_order_statuses' => json_encode(self::ELIGIBLE)]);
            }
        }
    }

    /**
     * Normalises config_brand_policies.settings->source_entry_policies.
     *
     * Values may be a list (multi-select) or a bare string (legacy single-select),
     * and 'preserve' is a sentinel for channel-sourced orders — not a status —
     * so it is passed through untouched.
     */
    private function normaliseBrandEntryPolicies(): void
    {
        if (! Schema::hasTable('config_brand_policies')) {
            return;
        }

        foreach (DB::table('config_brand_policies')->get(['id', 'settings']) as $row) {
            $settings = is_string($row->settings) ? json_decode($row->settings, true) : $row->settings;

            if (! is_array($settings) || ! isset($settings['source_entry_policies'])) {
                continue;
            }

            $policies = $settings['source_entry_policies'];

            if (! is_array($policies)) {
                continue;
            }

            $changed = false;

            foreach ($policies as $source => $value) {
                if (is_array($value)) {
                    $mapped = $this->mapList($value, self::ENTRY_MAP);

                    // A list that normalises to nothing falls back to the canonical
                    // entry state rather than being left empty — an empty entry
                    // policy would make order creation impossible for that brand.
                    if ($mapped === []) {
                        $mapped = ['in_progress'];
                    }

                    if ($mapped !== array_values($value)) {
                        $policies[$source] = $mapped;
                        $changed = true;
                    }

                    continue;
                }

                if (is_string($value) && array_key_exists($value, self::ENTRY_MAP)) {
                    $replacement = self::ENTRY_MAP[$value] ?? 'in_progress';

                    if ($replacement !== $value) {
                        $policies[$source] = $replacement;
                        $changed = true;
                    }
                }
            }

            if ($changed) {
                $settings['source_entry_policies'] = $policies;
                DB::table('config_brand_policies')
                    ->where('id', $row->id)
                    ->update(['settings' => json_encode($settings)]);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $list
     * @param  array<string, string|null>  $map
     * @return list<string>
     */
    private function mapList(array $list, array $map): array
    {
        $out = [];

        foreach ($list as $value) {
            if (! is_string($value)) {
                continue;
            }

            // Sentinel used by channel sources — not a status.
            if ($value === 'preserve') {
                $out[] = $value;

                continue;
            }

            if (array_key_exists($value, $map)) {
                $mapped = $map[$value];

                if ($mapped === null) {
                    continue; // explicitly dropped
                }

                $out[] = $mapped;

                continue;
            }

            $out[] = $value;
        }

        return array_values(array_unique($out));
    }

    /** @return list<string>|null */
    private function decodeList(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return array_values($raw);
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * Intentionally NOT reversible for order rows.
     *
     * `new → in_progress` cannot be undone: after this migration a row reading
     * `in_progress` may be a genuine in-progress order OR a normalised former
     * `new` order, and nothing distinguishes them. Guessing would corrupt data,
     * so `down()` restores only what is unambiguously restorable — the column
     * default — and leaves the rest, in the same spirit as the V3 migration's
     * own "merged statuses cannot be split back exactly" note.
     */
    public function down(): void
    {
        if (Schema::hasColumn('orders', 'status')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->string('status', 255)->nullable(false)->default('pending')->change();
            });
        }
    }
};
