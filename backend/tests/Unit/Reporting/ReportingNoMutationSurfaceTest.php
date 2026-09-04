<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use Modules\Reporting\Presentation\Http\Controllers\ReportCatalogueController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * TASK-ECOS-REPORTING-PLATFORM-FOUNDATION-002 §10 — "no mutation surface is exposed."
 *
 * Two independent, framework-free checks (no Laravel boot, no DB): the route file's own
 * `reporting` prefix group contains only GET registrations, and the controller class
 * itself exposes no create/update/delete-shaped public method. Either alone would catch a
 * regression; both together catch it whether introduced at the route layer or the
 * controller layer.
 */
final class ReportingNoMutationSurfaceTest extends TestCase
{
    public function test_reporting_route_group_contains_only_get_registrations(): void
    {
        $routesFile = __DIR__.'/../../../routes/api.php';
        $this->assertFileExists($routesFile);

        $contents = (string) file_get_contents($routesFile);

        $matched = preg_match(
            "/Route::middleware\('auth:sanctum'\)->prefix\('reporting'\)->group\(function \(\): void \{(.*?)\n\}\);/s",
            $contents,
            $matches,
        );

        $this->assertSame(1, $matched, "Could not locate the 'reporting' route group in routes/api.php.");

        $block = $matches[1];

        $this->assertMatchesRegularExpression('/Route::get\(/', $block, "The 'reporting' route group has no GET route at all.");

        foreach (['Route::post(', 'Route::put(', 'Route::patch(', 'Route::delete(', '->apiResource(', '->resource('] as $mutationVerb) {
            $this->assertStringNotContainsString(
                $mutationVerb,
                $block,
                "The 'reporting' route group contains '{$mutationVerb}' — Reporting must expose no mutation surface (ADR-045 Decision 2).",
            );
        }
    }

    public function test_report_catalogue_controller_exposes_no_mutation_method(): void
    {
        $reflection = new ReflectionClass(ReportCatalogueController::class);

        $publicMethods = array_map(
            static fn ($method) => $method->getName(),
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        );

        foreach (['store', 'create', 'update', 'destroy', 'delete'] as $forbidden) {
            $this->assertNotContains(
                $forbidden,
                $publicMethods,
                "ReportCatalogueController exposes a '{$forbidden}' method — Reporting must never mutate a row it does not own (ADR-045 Decision 2).",
            );
        }

        $this->assertContains('catalogue', $publicMethods);
        $this->assertContains('metrics', $publicMethods);
    }
}
