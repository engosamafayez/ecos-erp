<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Modules\AI\Application\Services\AIToolRegistry;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §10/§12 — a trivial
 * subclass of CORE-03's own AIToolRegistry, existing ONLY to give the container a distinct,
 * type-hintable identity for Voice's deliberately smaller tool list (architecture report,
 * CORE-03 REUSE: "a second instance of this exact class... no modification needed"). No new
 * logic; VoiceServiceProvider constructs it with only the 5 reused READ tools + the 3
 * voice-specific LOW-RISK CONFIRMED ACTION tools — never RunReportTool/GetReportsCatalogueTool/
 * SearchAuditLogTool/GetCustomerBalanceTool (§12: those are wrong for an anonymous caller).
 */
final class VoiceAIToolRegistry extends AIToolRegistry {}
