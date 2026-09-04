<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Enums;

/**
 * Exact vocabulary from ENTERPRISE-REPORTING-PLATFORM.md §18 Report Catalogue legend
 * (task §38's own gap-classification terms). All 7 phrases the architecture document
 * actually uses are represented; only the first 5 are ever a report's own primary
 * classification in the ratified V1 catalogue (§18), the remaining 2 appear as
 * "Known dependency" / "Later Reports" reasons (§18/§19) and are kept here so future
 * catalogue entries can cite the same fixed vocabulary rather than inventing new terms.
 */
enum GapClassification: string
{
    case ReadyExistingQuery = 'ready_existing_query';
    case ReadySmallQueryRequired = 'ready_small_query_api_required';
    case ReportingReadModelRequired = 'reporting_read_model_required';
    case FinanceDependency = 'finance_dependency';
    case UpstreamFeatureRequired = 'upstream_feature_required';
    case UpstreamDataContractRequired = 'upstream_data_contract_required';
    case UpstreamDataQualityDependency = 'upstream_data_quality_dependency';
}
