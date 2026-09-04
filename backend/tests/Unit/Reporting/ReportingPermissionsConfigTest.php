<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use Modules\Reporting\Domain\Enums\ReportCategory;
use PHPUnit\Framework\TestCase;

/**
 * TASK-ECOS-REPORTING-PLATFORM-FOUNDATION-002 §7 — "add authorization foundation using
 * existing IAM/authorization authority. Do not create a parallel permission engine."
 *
 * Cross-checks the three places a Reporting permission must agree with each other:
 * config/permissions.php's documentation registry, the ReportCategory enum, and the
 * seed migration. Framework-free: config/permissions.php is a plain `return [...]` file,
 * loaded here with a direct `require`, not Laravel's config() helper.
 */
final class ReportingPermissionsConfigTest extends TestCase
{
    public function test_config_permissions_reports_block_matches_report_category_enum_exactly(): void
    {
        $config = require __DIR__.'/../../../config/permissions.php';

        $this->assertArrayHasKey('reports', $config['modules'], "config/permissions.php is missing the 'reports' module block.");

        $configuredCategories = array_keys($config['modules']['reports']);
        $enumCategories = array_map(static fn (ReportCategory $c): string => $c->value, ReportCategory::cases());

        sort($configuredCategories);
        sort($enumCategories);

        $this->assertSame($enumCategories, $configuredCategories, "config/permissions.php's 'reports' categories must match ReportCategory::cases() exactly.");

        foreach ($config['modules']['reports'] as $category => $actions) {
            $this->assertSame(['view'], $actions, "'{$category}' must carry exactly the ['view'] action set — V1 grants no other Reporting action.");
        }
    }

    public function test_seed_migration_inserts_exactly_the_enum_categories(): void
    {
        $migrationFile = glob(__DIR__.'/../../../Modules/Reporting/Infrastructure/Database/Migrations/*_seed_reporting_permissions_table.php')[0] ?? null;
        $this->assertNotNull($migrationFile, 'Reporting permissions seed migration not found.');

        $contents = (string) file_get_contents($migrationFile);

        foreach (ReportCategory::cases() as $category) {
            $this->assertStringContainsString(
                "'{$category->value}'",
                $contents,
                "Seed migration does not reference category '{$category->value}'.",
            );
        }
    }
}
