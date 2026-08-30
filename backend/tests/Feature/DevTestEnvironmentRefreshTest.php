<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-TEST-ENV-DEV-PHPUNIT-ENABLE-001 — Part 11.
 *
 * The controlled RefreshDatabase probe. RefreshDatabase performs a real
 * migrate:fresh, so this is the single most dangerous operation a test process
 * can perform — it drops and recreates every table in whatever database it
 * resolves to. This test exists to prove that the database it resolves to is
 * ecos_dev_test and nothing else.
 *
 * Engineering infrastructure only. No business assertions.
 */
final class DevTestEnvironmentRefreshTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_DB = 'ecos_dev_test';

    public function test_part11_refresh_database_operated_on_the_dev_test_database_only(): void
    {
        // By the time this body runs, RefreshDatabase has already migrated.
        // Whatever database the server reports here is the one it just rebuilt.
        $serverSide = DB::selectOne('SELECT DATABASE() AS db')->db;
        $live = DB::connection()->getDatabaseName();

        $tables = (int) DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.tables WHERE table_schema = ?',
            [self::EXPECTED_DB],
        )->c;

        $migrations = (int) DB::selectOne('SELECT COUNT(*) AS c FROM migrations')->c;

        fwrite(STDERR, PHP_EOL.'REFRESHDATABASE PROBE'.PHP_EOL
            .'  SELECT DATABASE()      = '.$serverSide.PHP_EOL
            .'  live connection        = '.$live.PHP_EOL
            .'  tables in '.self::EXPECTED_DB.' = '.$tables.PHP_EOL
            .'  migrations applied     = '.$migrations.PHP_EOL);

        self::assertSame(self::EXPECTED_DB, $serverSide,
            'RefreshDatabase must have operated on '.self::EXPECTED_DB);
        self::assertSame(self::EXPECTED_DB, $live);

        // Migration actually happened in this database — proof the rebuild
        // landed here rather than somewhere else.
        self::assertGreaterThan(100, $tables, 'The schema must have been built in '.self::EXPECTED_DB);
        self::assertGreaterThan(0, $migrations, 'Migrations must be recorded in '.self::EXPECTED_DB);

        // And MAIN remains categorically out of reach from this connection.
        $names = array_map(
            static fn ($row) => array_values((array) $row)[0],
            DB::select('SHOW DATABASES'),
        );
        self::assertNotContains('ecos_erp', $names);
        self::assertNotContains('ecos_erp_test', $names);
    }
}
