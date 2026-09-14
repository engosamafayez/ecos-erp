<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use Modules\AI\Application\Services\AIToolInvoker;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §10 — a trivial subclass of
 * CORE-03's own AIToolInvoker, existing only to give the container a distinct type for Voice's
 * binding (constructed in VoiceServiceProvider with a VoiceAIToolRegistry and the
 * 'cep.voice.use' entry permission — never 'ai.assistant.use'). No overridden behavior: the
 * exact same 9-step choke point (audit → entry permission → registry lookup → tool's own
 * domain permission → tenant scope → input-size guard → audit allow → bounded execute →
 * audit outcome) applies identically to both Resident AI and Voice.
 */
final class VoiceAIToolInvoker extends AIToolInvoker {}
