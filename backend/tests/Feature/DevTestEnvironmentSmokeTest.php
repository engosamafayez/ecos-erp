<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TASK-TEST-ENV-DEV-PHPUNIT-ENABLE-001 — Parts 9 and 10.
 *
 * Engineering test-environment infrastructure. Contains NO business assertions:
 * it exists only to prove that a PHPUnit process running in the engineering DEV
 * runner resolves to the isolated DEV test database and can read and write there
 * without touching MAIN.
 *
 * Deliberately does NOT use RefreshDatabase — this file must be runnable before
 * the schema exists. The write probe uses a TEMPORARY table, which lives in the
 * current session only and disappears when the connection closes, so it proves a
 * real write against a real database while leaving nothing behind.
 */
final class DevTestEnvironmentSmokeTest extends TestCase
{
    private const EXPECTED_DB = 'ecos_dev_test';

    /** Databases that must never be the target of a test process. */
    private const FORBIDDEN_DBS = ['ecos_erp', 'ecos_erp_test', 'ecos_dev'];

    public function test_part9_runtime_resolves_to_the_isolated_dev_test_database(): void
    {
        // 1 — environment
        self::assertSame('testing', config('app.env'), 'APP_ENV must resolve to testing.');

        // 2 — driver
        self::assertSame('mysql', config('database.default'));
        self::assertSame('mysql', config('database.connections.mysql.driver'));

        // 3 — configured name, read from the runtime, never hardcoded into a pass
        $configured = config('database.connections.mysql.database');

        // 4 — a real connection, and the name the SERVER reports for it
        $live = DB::connection()->getDatabaseName();
        $serverSide = DB::selectOne('SELECT DATABASE() AS db')->db;

        fwrite(STDERR, PHP_EOL.'DEV TEST ENV PROBE'.PHP_EOL
            .'  app.env          = '.config('app.env').PHP_EOL
            .'  config db        = '.$configured.PHP_EOL
            .'  live connection  = '.$live.PHP_EOL
            .'  SELECT DATABASE()= '.$serverSide.PHP_EOL
            .'  db.host          = '.config('database.connections.mysql.host').PHP_EOL
            .'  db.port          = '.config('database.connections.mysql.port').PHP_EOL
            .'  config cached    = '.(app()->configurationIsCached() ? 'YES' : 'no').PHP_EOL);

        self::assertSame(self::EXPECTED_DB, $configured, 'config() must resolve to '.self::EXPECTED_DB);
        self::assertSame(self::EXPECTED_DB, $live, 'The live connection must be '.self::EXPECTED_DB);
        self::assertSame(self::EXPECTED_DB, $serverSide, 'The SERVER must agree it is '.self::EXPECTED_DB);

        // 5 — explicitly not any MAIN or runtime database
        foreach (self::FORBIDDEN_DBS as $forbidden) {
            self::assertNotSame($forbidden, $serverSide, "Tests must never target [{$forbidden}].");
        }

        // A cached config would pin database.* at boot and defeat the guards.
        self::assertFalse(
            app()->configurationIsCached(),
            'A cached config would override the forced test database — the runner must not carry one.',
        );

        // 6 — harmless SELECT
        self::assertSame(2, (int) DB::selectOne('SELECT 1 + 1 AS n')->n);
    }

    public function test_part9_main_databases_are_not_reachable_from_this_connection(): void
    {
        $names = array_map(
            static fn ($row) => array_values((array) $row)[0],
            DB::select('SHOW DATABASES'),
        );

        fwrite(STDERR, 'DEV TEST ENV reachable databases: '.implode(', ', $names).PHP_EOL);

        self::assertContains(self::EXPECTED_DB, $names);
        self::assertNotContains('ecos_erp', $names, 'MAIN database must be unreachable.');
        self::assertNotContains('ecos_erp_test', $names, 'MAIN test database must be unreachable.');
    }

    public function test_part10_write_probe_lands_in_the_dev_test_database_and_leaves_nothing(): void
    {
        $table = 'ecos_env_probe_tmp';

        // TEMPORARY: session-scoped. Real write, real read-back, no residue.
        DB::statement("CREATE TEMPORARY TABLE {$table} (id INT PRIMARY KEY, note VARCHAR(64))");

        try {
            DB::insert("INSERT INTO {$table} (id, note) VALUES (?, ?)", [1, 'dev-env-probe']);

            $row = DB::selectOne("SELECT id, note FROM {$table} WHERE id = 1");
            self::assertNotNull($row, 'The probe row must be readable back.');
            self::assertSame('dev-env-probe', $row->note);

            // The write must have landed in the DEV test database, nowhere else.
            $where = DB::selectOne('SELECT DATABASE() AS db')->db;
            self::assertSame(self::EXPECTED_DB, $where, 'The write must have occurred in '.self::EXPECTED_DB);

            fwrite(STDERR, "DEV TEST ENV write probe: 1 row written and read back in {$where}".PHP_EOL);
        } finally {
            DB::statement("DROP TEMPORARY TABLE IF EXISTS {$table}");
        }

        // Cleaned: the temporary table is gone from this session.
        $still = DB::select(
            'SELECT table_name FROM information_schema.tables WHERE table_schema = ? AND table_name = ?',
            [self::EXPECTED_DB, $table],
        );
        self::assertCount(0, $still, 'The probe table must leave no persistent residue.');
    }
}
