<?php

declare(strict_types=1);

namespace Modules\Operations\Loading\Domain\Services;

use RuntimeException;

/**
 * The actor acted on a quantity that has since changed.
 *
 * Distinct from a plain business refusal because the correct client response is
 * different: not "you may not do this", but "refresh and look again". Controllers map
 * it to 409 Conflict so a stale confirmation is never silently applied.
 *
 * Extends RuntimeException so existing `catch (RuntimeException)` boundaries continue
 * to handle it if a caller does not distinguish it.
 */
class StaleQuantityException extends RuntimeException {}
