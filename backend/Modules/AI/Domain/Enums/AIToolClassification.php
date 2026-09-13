<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Enums;

/**
 * §9/§14 — V1 allows only these two. No WRITE classification exists yet; adding
 * one is explicitly out of this task's scope (§33).
 */
enum AIToolClassification: string
{
    case Read = 'read';
    case NavigationMetadata = 'navigation_metadata';
}
