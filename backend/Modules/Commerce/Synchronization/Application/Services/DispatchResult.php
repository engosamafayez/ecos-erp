<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

/**
 * The outcome of one WooOutboundCommandDispatcher call — transport-agnostic, so a caller
 * (ProductSyncJob, PriceSyncJob, etc.) logs success/failure identically regardless of whether
 * the command travelled via direct Woo REST or the Connector plugin.
 */
final class DispatchResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?int $status,
        /** @var array<string, mixed> */
        public readonly array $data,
        public readonly ?string $error,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(int $status, array $data = []): self
    {
        return new self(true, $status, $data, null);
    }

    public static function failure(string $error): self
    {
        return new self(false, null, [], $error);
    }
}
