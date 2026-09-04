<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use Modules\Reporting\Domain\Contracts\SourceReportQueryContract;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * TASK-ECOS-REPORTING-PLATFORM-FOUNDATION-002 §10 — "tenant/company scope contracts are
 * preserved where applicable."
 *
 * No report handler exists yet (foundation-only, §5/§6), so there is nothing to execute
 * against a real tenant. What must exist, and what this test pins, is the *contract*: a
 * future Task 3+ implementation of {@see SourceReportQueryContract} cannot compile without
 * accepting a {@see ReportQueryContext} carrying a company id — the scope requirement is
 * structural (a type-level parameter), not a convention a future developer could forget
 * (ENTERPRISE-REPORTING-PLATFORM.md §9: "Report access != company-wide data access").
 */
final class SourceReportQueryContractTest extends TestCase
{
    public function test_source_report_query_contract_requires_a_report_query_context(): void
    {
        $interface = new ReflectionClass(SourceReportQueryContract::class);
        $this->assertTrue($interface->isInterface());

        $execute = $interface->getMethod('execute');
        $parameters = $execute->getParameters();

        $this->assertGreaterThanOrEqual(1, count($parameters), 'execute() must accept at least a query context parameter.');

        $first = $parameters[0];
        $type = $first->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame(
            ReportQueryContext::class,
            $type->getName(),
            'execute()\'s first parameter must be typed to ReportQueryContext, so tenant scope cannot be omitted by a future implementer.',
        );
        $this->assertFalse($type->allowsNull(), 'The query context parameter must not be nullable — a report query with no tenant scope at all must be a type error, not a runtime check.');
    }

    public function test_report_query_context_carries_a_required_non_nullable_company_id(): void
    {
        $class = new ReflectionClass(ReportQueryContext::class);
        $constructor = $class->getConstructor();
        $this->assertNotNull($constructor);

        $companyId = self::findParameter($constructor, 'companyId');
        $this->assertNotNull($companyId, 'ReportQueryContext must declare a companyId constructor parameter.');
        $this->assertFalse($companyId->isOptional(), 'companyId must be required — every report query context must carry a tenant scope.');

        $type = $companyId->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $type);
        $this->assertSame('string', $type->getName());
        $this->assertFalse($type->allowsNull());
    }

    public function test_execute_has_no_mutation_shaped_sibling_method(): void
    {
        // A read-only contract by construction (ADR-045 Decision 2): no create/update/
        // delete counterpart exists on the interface for a future implementer to notice
        // and mistakenly wire up.
        $interface = new ReflectionClass(SourceReportQueryContract::class);
        $methodNames = array_map(static fn (ReflectionMethod $m): string => $m->getName(), $interface->getMethods());

        $this->assertSame(['execute'], $methodNames);
    }

    private static function findParameter(ReflectionMethod $method, string $name): ?ReflectionParameter
    {
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() === $name) {
                return $parameter;
            }
        }

        return null;
    }
}
