<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * RC-001 Performance hardening: add composite indexes on marketing_campaign_insights
 * to support the Intelligence layer's date-range + level + connection filter queries.
 *
 * All three new indexes are partial-composite — they cover the most frequent
 * query patterns in MarketingKpiEngine without duplicating the existing
 * (campaign_id, level, date_start, date_stop) and (level, date_start) indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Covers: WHERE marketing_connection_id = ? AND level = ? AND date_start BETWEEN ?
        // Used by: all Intelligence queries when a connection_id filter is active.
        DB::statement('
            CREATE INDEX IF NOT EXISTS mkt_ins_conn_level_date_idx
            ON marketing_campaign_insights (marketing_connection_id, level, date_start)
        ');

        // Covers: WHERE level = ? AND date_start BETWEEN ? AND date_stop BETWEEN ?
        // Extending the existing level+date_start index to include date_stop for backfill range queries.
        DB::statement('
            CREATE INDEX IF NOT EXISTS mkt_ins_level_date_range_idx
            ON marketing_campaign_insights (level, date_start, date_stop)
        ');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS mkt_ins_conn_level_date_idx');
        DB::statement('DROP INDEX IF EXISTS mkt_ins_level_date_range_idx');
    }
};
