<?php

declare(strict_types=1);

namespace Modules\AI\Domain\Enums;

/**
 * §21 — every tool result carries one of these; a "fact" is never returned
 * dressed up as a guess, and an absence is never silently swallowed into null.
 */
enum AIToolStatus: string
{
    case Success = 'success';
    case NotFound = 'not_found';
    case Unavailable = 'unavailable';
    case Denied = 'denied';
    case InvalidInput = 'invalid_input';
    case Error = 'error';
}
