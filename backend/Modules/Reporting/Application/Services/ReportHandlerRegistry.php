<?php

declare(strict_types=1);

namespace Modules\Reporting\Application\Services;

use Modules\Reporting\Application\Exceptions\DuplicateReportHandlerException;
use Modules\Reporting\Application\Exceptions\ReportNotExecutableException;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;

/**
 * Explicit report-handler registry (§4). Built once, from an explicit list handed in by
 * {@see \Modules\Reporting\Infrastructure\Providers\ReportingServiceProvider} — never by
 * scanning/auto-discovering classes, so "every executable report ID maps to exactly one
 * handler" is enforced at construction time, not left to be discovered at request time.
 *
 * Catalogue definitions (Task 2) remain the source of report metadata; this registry, and
 * the handlers it holds, are the source of query behaviour (§4) — the 35-report catalogue
 * is never duplicated here, only the small subset of ids this task actually executes.
 */
final class ReportHandlerRegistry
{
    /** @var array<string, ReportHandlerInterface> */
    private array $handlers = [];

    /**
     * @param  iterable<ReportHandlerInterface>  $handlers
     *
     * @throws DuplicateReportHandlerException
     */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $id = $handler->reportId();

            if (isset($this->handlers[$id])) {
                throw DuplicateReportHandlerException::forId($id);
            }

            $this->handlers[$id] = $handler;
        }
    }

    public function has(string $reportId): bool
    {
        return isset($this->handlers[$reportId]);
    }

    /**
     * @throws ReportNotExecutableException
     */
    public function resolve(string $reportId): ReportHandlerInterface
    {
        return $this->handlers[$reportId] ?? throw ReportNotExecutableException::forId($reportId);
    }

    /** @return list<string> */
    public function registeredReportIds(): array
    {
        return array_keys($this->handlers);
    }
}
