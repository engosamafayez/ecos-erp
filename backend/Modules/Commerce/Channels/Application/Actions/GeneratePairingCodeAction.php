<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Str;
use Modules\Commerce\Channels\Domain\Contracts\ChannelRepositoryInterface;
use Modules\Commerce\Channels\Domain\Exceptions\ChannelNotFoundException;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001-R2 §14 — an ECOS operator (already authenticated,
 * already the one who configured this Channel's Woo REST credential) generates a short-lived,
 * single-use pairing code for the merchant to paste into the WooCommerce Connector plugin.
 *
 * The plaintext code is returned ONCE, to the operator's own authenticated ECOS session — it is
 * never stored in plaintext (only its hash), mirroring the discipline every other secret in
 * this codebase already follows (never logged, never re-displayed).
 */
final class GeneratePairingCodeAction extends BaseAction
{
    private const TTL_MINUTES = 15;

    public function __construct(
        private readonly ChannelRepositoryInterface $channels,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $channelId = (string) ($arguments[0] ?? '');
        $channel = $this->channels->findById($channelId);

        if ($channel === null) {
            throw new ChannelNotFoundException($channelId);
        }

        // 10 characters from Str::random's unambiguous alphabet is short enough to type/paste
        // by hand and, combined with the 15-minute expiry and single-use consumption, is not a
        // meaningfully brute-forceable window for an unauthenticated exchange endpoint.
        $code = strtoupper(Str::random(10));

        $channel->update([
            'pairing_code_hash' => hash('sha256', $code),
            'pairing_code_expires_at' => now()->addMinutes(self::TTL_MINUTES),
        ]);

        return OperationResult::success([
            'pairing_code' => $code,
            'expires_at' => $channel->pairing_code_expires_at?->toIso8601String(),
        ], 'Pairing code generated. It is valid for '.self::TTL_MINUTES.' minutes and shown only once.');
    }
}
