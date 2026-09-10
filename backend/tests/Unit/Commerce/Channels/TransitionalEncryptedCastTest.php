<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce\Channels;

use Illuminate\Container\Container;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Facade;
use Modules\Commerce\Channels\Domain\Models\ChannelCredential;
use Modules\Commerce\Channels\Infrastructure\Casts\TransitionalEncryptedCast;
use PHPUnit\Framework\TestCase;

/**
 * TASK-...-025 (P12) — proves the transitional cast round-trips AND falls back for legacy
 * plaintext without needing a database: only the Crypt facade's underlying Encrypter and a bare
 * (unsaved) Eloquent Model instance are needed, neither of which touches a DB connection.
 */
final class TransitionalEncryptedCastTest extends TestCase
{
    private TransitionalEncryptedCast $cast;

    private ChannelCredential $model;

    protected function setUp(): void
    {
        parent::setUp();

        // Crypt::encryptString()/decryptString() resolve the encrypter via the app container;
        // bind a real Encrypter directly rather than booting the framework.
        $container = Container::getInstance();
        // Crypt's facade accessor is the string key 'encrypter', not the Encrypter contract.
        $container->singleton('encrypter', fn () => new Encrypter(str_repeat('a', 32), 'AES-256-CBC'));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $this->cast = new TransitionalEncryptedCast();
        $this->model = new ChannelCredential();
    }

    public function test_set_then_get_round_trips_transparently(): void
    {
        $ciphertext = $this->cast->set($this->model, 'consumer_key', 'ck_live_abc123', []);

        self::assertNotSame('ck_live_abc123', $ciphertext, 'set() must not store plaintext.');
        self::assertSame('ck_live_abc123', $this->cast->get($this->model, 'consumer_key', $ciphertext, []));
    }

    public function test_legacy_plaintext_value_falls_back_instead_of_throwing(): void
    {
        // A row written before TASK-...-025 — never passed through set(), so it is NOT
        // ciphertext. The stock Laravel `encrypted` cast would throw DecryptException here.
        $legacyPlaintext = 'ck_live_written_before_encryption_existed';

        self::assertSame($legacyPlaintext, $this->cast->get($this->model, 'consumer_key', $legacyPlaintext, []));
    }

    public function test_a_re_saved_legacy_value_becomes_real_ciphertext(): void
    {
        $legacyPlaintext = 'cs_live_legacy_secret';

        // Simulates EncryptLegacyChannelCredentialsCommand re-saving a row: get() falls back to
        // plaintext (proven above), then set() on the SAME value always encrypts.
        $readBack = $this->cast->get($this->model, 'consumer_secret', $legacyPlaintext, []);
        $reEncrypted = $this->cast->set($this->model, 'consumer_secret', $readBack, []);

        self::assertNotSame($legacyPlaintext, $reEncrypted);
        self::assertSame($legacyPlaintext, $this->cast->get($this->model, 'consumer_secret', $reEncrypted, []));
    }

    public function test_null_passes_through_untouched(): void
    {
        self::assertNull($this->cast->set($this->model, 'consumer_key', null, []));
        self::assertNull($this->cast->get($this->model, 'consumer_key', null, []));
    }
}
