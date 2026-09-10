<?php

declare(strict_types=1);

namespace Modules\Commerce\OrderImport\Application\DTO;

use Illuminate\Support\Carbon;

/**
 * TASK-...-025 (W3/W5/W9) — controls the ONE existing importer for both the live/catch-up path
 * and the explicit historical-import mode. No second import engine.
 */
final class OrderImportOptionsDTO
{
    public function __construct(
        public readonly ?Carbon $after = null,
        public readonly bool $historical = false,
        public readonly ?string $batchId = null,
    ) {}
}
