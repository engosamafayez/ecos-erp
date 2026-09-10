<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Infrastructure\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;

/**
 * TASK-...-025 (P12) — one-time, idempotent backfill converting legacy plaintext
 * consumer_key/consumer_secret rows to encrypted-at-rest.
 *
 * NOT invoked by this task (Task 025-R1 explicitly forbids running a credential migration
 * against canonical/DEV from this source task). This command is source-complete and safe to run
 * later, deliberately, by whoever owns that rollout step.
 *
 * Idempotent: a row already encrypted (TransitionalEncryptedCast::get() decrypts it
 * successfully) is left untouched — re-running after a partial prior run, or running it twice by
 * mistake, never double-encrypts or corrupts an already-migrated row.
 *
 * Usage: php artisan woo:encrypt-legacy-credentials [--dry-run]
 */
final class EncryptLegacyChannelCredentialsCommand extends Command
{
    protected $signature = 'woo:encrypt-legacy-credentials {--dry-run : Report what would change without writing anything}';

    protected $description = 'Encrypt any WooCommerce channel credentials still stored in plaintext (idempotent).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $migrated = 0;
        $alreadyEncrypted = 0;

        ChannelCredential::query()->select(['id', 'channel_id', 'consumer_key', 'consumer_secret'])
            ->chunkById(200, function ($credentials) use (&$migrated, &$alreadyEncrypted, $dryRun): void {
                foreach ($credentials as $credential) {
                    // Read the RAW column value, bypassing the model cast, to detect whether it
                    // is already ciphertext — the cast's own get() would silently paper over the
                    // distinction we need here.
                    $rawKey = $credential->getRawOriginal('consumer_key');
                    $rawSecret = $credential->getRawOriginal('consumer_secret');

                    $keyAlreadyEncrypted = $this->isEncrypted($rawKey);
                    $secretAlreadyEncrypted = $this->isEncrypted($rawSecret);

                    if ($keyAlreadyEncrypted && $secretAlreadyEncrypted) {
                        $alreadyEncrypted++;

                        continue;
                    }

                    $migrated++;
                    $this->line(($dryRun ? '[dry-run] would encrypt' : 'encrypting')." channel_id={$credential->channel_id}");

                    if ($dryRun) {
                        continue;
                    }

                    // Re-saving through the model routes both columns through
                    // TransitionalEncryptedCast::set(), which always encrypts — regardless of
                    // whether the value being replaced was plaintext or already ciphertext.
                    $credential->consumer_key = $credential->consumer_key;
                    $credential->consumer_secret = $credential->consumer_secret;
                    $credential->save();
                }
            });

        $this->info("Done. Migrated: {$migrated}. Already encrypted: {$alreadyEncrypted}.".($dryRun ? ' (dry-run — nothing written)' : ''));

        return self::SUCCESS;
    }

    private function isEncrypted(?string $rawValue): bool
    {
        if ($rawValue === null) {
            return true;
        }

        try {
            Crypt::decryptString($rawValue);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
