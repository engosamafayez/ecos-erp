<?php

declare(strict_types=1);

namespace Modules\POS\Application\Commands;

final readonly class OpenSessionCommand
{
    public function __construct(
        public string $terminalId,
        public string $cashierId,
        public string $deviceFingerprint,
        public string $ipAddress,
        public string $deviceType = 'browser',
    ) {}
}
