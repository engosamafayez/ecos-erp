<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Infrastructure\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * TASK-...-025 (P12) — encrypts at rest WITHOUT breaking rows written before this task existed.
 *
 * Laravel's stock `encrypted` cast throws DecryptException on a plaintext value, because it
 * always attempts to decrypt on read. Every ChannelCredential row written before this task is
 * exactly such a plaintext value (TASK-...-024 confirmed this), so dropping the stock cast
 * straight onto this column would make every existing Woo integration unusable the instant this
 * ships — the one failure mode this task was explicitly told to avoid.
 *
 * get(): try to decrypt; a legacy plaintext value fails decryption and is returned as-is.
 * set(): ALWAYS encrypts. Every value written from this task forward is ciphertext, regardless
 * of whether the value being replaced was plaintext or already encrypted.
 *
 * A one-time, idempotent backfill (EncryptLegacyChannelCredentialsCommand, not invoked by this
 * task) re-saves existing rows through set() to convert them; until it runs, this cast makes old
 * and new rows both work transparently.
 */
final class TransitionalEncryptedCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            return (string) $value;
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString((string) $value);
    }
}
