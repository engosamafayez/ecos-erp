<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/**
 * ENTERPRISE-REPORTING-PLATFORM.md §6 Data Architecture & Read Strategy / ADR-045 Decision 3.
 *
 * Patterns A/B/C only — the V1-adopted set. Pattern D (event-fed rollup) and database views
 * are explicitly "Reserved, not adopted in V1" and are deliberately not represented as a
 * case here; adding one before a real decision adopts it would misrepresent this task's own
 * source document.
 */
enum ReadStrategy: string
{
    /** Call an existing source-module query/read-service verbatim. Primary pattern. */
    case CallExistingSourceService = 'call_existing_source_service';

    /** A new query service added inside the owning (non-Reporting) module. */
    case NewSourceOwnedQueryService = 'new_source_owned_query_service';

    /** Reporting-owned composition of one-or-more Pattern A/B services (cross-domain only). */
    case ReportingOwnedComposition = 'reporting_owned_composition';
}
