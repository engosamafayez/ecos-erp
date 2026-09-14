<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Enums;

/**
 * §9/§14 — CORE-03 V1 allowed only Read/NavigationMetadata; adding a write classification was
 * explicitly out of that task's scope (§33).
 *
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §12 adds ConfirmedAction
 * for Voice's own approved low-risk write tools (create follow-up/schedule callback/create
 * support ticket) — none move money, alter state destructively, or expose anything sensitive,
 * so they are classified as always-safe-to-execute rather than needing
 * {@see \Modules\AI\Domain\Contracts\AIToolInterface::requiresConfirmation()}'s not-yet-built
 * interactive-confirmation mechanism (every tool, including these, still returns false there —
 * this is additive metadata only; AIToolInvoker never branches on classification today).
 */
enum AIToolClassification: string
{
    case Read = 'read';
    case NavigationMetadata = 'navigation_metadata';
    case ConfirmedAction = 'confirmed_action';
}
