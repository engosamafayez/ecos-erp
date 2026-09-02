<?php

declare(strict_types=1);

namespace Modules\Finance\Shared\Domain\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Shared\Domain\Models\FinanceCommandReceipt;
use Modules\Finance\Shared\Domain\ValueObjects\IdempotentResult;

/**
 * Command-level idempotency for interactive/API Finance write commands —
 * payment/receipt creation, allocation reversal, and similar — the layer
 * ABOVE {@see \Modules\Finance\Posting\Domain\Services\PostingCoordinator},
 * which already deduplicates EVENTS reaching the ledger and is untouched by
 * this class.
 *
 * ┌─ WHY THE COMMAND RUNS INSIDE THE CLAIM, NOT BEFORE IT ───────────────────┐
 * │ PostingCoordinator runs its work FIRST, then records a receipt, and on a  │
 * │ race falls back to the winner's journal — safe there because posting a    │
 * │ given PostingRequest is already meaningful to re-attempt. The commands    │
 * │ this guard covers have no such property: calling                          │
 * │ SupplierPayment::create() twice always makes two distinct rows, with      │
 * │ nothing downstream to collapse them back into one. So here the command    │
 * │ runs INSIDE the same transaction that inserts the receipt. A losing       │
 * │ concurrent attempt's receipt insert BLOCKS on the database's own unique   │
 * │ index until the winner commits or rolls back; if the winner commits, the  │
 * │ loser's whole transaction — including whatever the command wrote — rolls  │
 * │ back with it. At most one underlying financial row is ever left behind,   │
 * │ using only ordinary transactional guarantees. A command that throws       │
 * │ leaves no receipt row at all, so the key is immediately retryable — there │
 * │ is no separate "processing"/"failed" status to expire or reconcile.       │
 * └────────────────────────────────────────────────────────────────────────────┘
 */
final class CommandIdempotencyGuard
{
    /**
     * Execute $command exactly once per (company, command type, idempotency
     * key). A null/empty key means no retry-safety was requested — $command
     * simply runs, uncoordinated, exactly as every existing Finance write
     * path does today.
     *
     * @param  array<string, mixed>  $payload  Business-meaningful command
     *   fields only — see fingerprint() for exactly what must NOT be included.
     * @param  callable(): Model  $command  Must be pure persistence (no
     *   external side effects): on a lost race its effects are rolled back.
     */
    public function execute(
        string $companyId,
        string $commandType,
        ?string $idempotencyKey,
        array $payload,
        callable $command,
        ?int $actorId = null,
    ): IdempotentResult {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return IdempotentResult::firstExecution($command());
        }

        $fingerprint = self::fingerprint($payload);

        $existing = $this->existingReceipt($companyId, $commandType, $idempotencyKey);
        if ($existing !== null) {
            return $this->resolve($existing, $fingerprint);
        }

        try {
            return DB::transaction(function () use ($companyId, $commandType, $idempotencyKey, $fingerprint, $command, $actorId): IdempotentResult {
                $result = $command();

                FinanceCommandReceipt::create([
                    'company_id' => $companyId,
                    'command_type' => $commandType,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'result_type' => get_class($result),
                    'result_id' => (string) $result->uuid,
                    'created_by' => $actorId,
                ]);

                return IdempotentResult::firstExecution($result);
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the race: our own transaction (command included) has just
            // been rolled back. The winner is now committed and visible.
            $winner = $this->existingReceipt($companyId, $commandType, $idempotencyKey);
            if ($winner === null) {
                throw FinanceException::idempotencyKeyRaceUnresolved();
            }

            return $this->resolve($winner, $fingerprint);
        }
    }

    /**
     * A deterministic fingerprint of a command's business payload.
     *
     * Deterministic regardless of key-construction order (recursively
     * ksort()'d) and stable across the same rounding Finance already applies
     * to every stored amount (floats normalised to 4 decimal places — see
     * JournalEngine/AllocationEngine's own round($x, 4) convention — so
     * 100.0 and 100.00001 fingerprint identically, matching how Finance
     * already treats them as the same amount everywhere else).
     *
     * Callers MUST pass only business-meaningful fields: the amount, party,
     * document reference, funding account, and similar — never a request
     * timestamp, request/trace ID, auth token, or other transport-only value
     * that would make two logically identical commands fingerprint
     * differently.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fingerprint(array $payload): string
    {
        return hash('sha256', (string) json_encode(
            self::normalize($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function normalize(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::normalize($value);
            } elseif (is_float($value)) {
                $data[$key] = round($value, 4);
            }
        }

        return $data;
    }

    private function resolve(FinanceCommandReceipt $existing, string $fingerprint): IdempotentResult
    {
        if (! hash_equals($existing->request_fingerprint, $fingerprint)) {
            throw FinanceException::idempotencyKeyConflict($existing->idempotency_key);
        }

        /** @var class-string<Model> $resultClass */
        $resultClass = $existing->result_type;
        $result = $resultClass::query()->where('uuid', $existing->result_id)->firstOrFail();

        return IdempotentResult::replay($result);
    }

    private function existingReceipt(string $companyId, string $commandType, string $idempotencyKey): ?FinanceCommandReceipt
    {
        return FinanceCommandReceipt::query()
            ->where('company_id', $companyId)
            ->where('command_type', $commandType)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }
}
