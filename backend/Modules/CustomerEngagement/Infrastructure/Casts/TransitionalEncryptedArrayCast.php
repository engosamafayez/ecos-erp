<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Infrastructure\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §3C — encrypts
 * `ChannelProvider.credentials` at rest without breaking every WhatsApp/Instagram/Messenger
 * row written before this task, which stored it as plain JSON despite the original migration's
 * own comment claiming otherwise (see the CRM-03 architecture report, gap #3).
 *
 * Same fallback-on-read discipline as
 * {@see \Modules\Commerce\Channels\Infrastructure\Casts\TransitionalEncryptedCast}, adapted for
 * a JSON/array column instead of a single string: get() tries to decrypt-then-json-decode; a
 * legacy plaintext-JSON value fails decryption and is decoded as-is instead. set() always
 * json-encodes then encrypts. No backfill command is required or invoked by this cast — old and
 * new rows both read correctly forever; a future one-time re-save (not part of this task) would
 * simply convert a row to ciphertext by writing it back through set().
 */
final class TransitionalEncryptedArrayCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        try {
            $json = Crypt::decryptString((string) $value);
        } catch (DecryptException) {
            $json = (string) $value;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR));
    }
}
