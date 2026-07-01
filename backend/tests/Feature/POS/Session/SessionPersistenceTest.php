<?php

declare(strict_types=1);

namespace Tests\Feature\POS\Session;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\POS\Session\Domain\Contracts\SessionRepositoryInterface;
use Modules\POS\Session\Domain\Enums\DeviceType;
use Modules\POS\Session\Domain\Models\Session;
use Modules\POS\Session\Domain\ValueObjects\DeviceFingerprint;
use Modules\POS\Shared\Domain\Enums\SessionStatus;
use Tests\TestCase;

/**
 * PKG-POS-004: Session repository persistence tests.
 *
 * Requires a running PostgreSQL database. These tests verify that the
 * EloquentSessionRepository correctly persists and retrieves sessions,
 * and that Eloquent casts survive the DB round-trip.
 *
 * Run these tests when the database is available:
 *   php artisan test tests/Feature/POS/Session/SessionPersistenceTest.php
 */
final class SessionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private SessionRepositoryInterface $repository;

    // No FK constraints on these — any UUID is valid.
    private const TERMINAL_ID = 'a0000000-0000-4000-a000-000000000001';
    private const CASHIER_ID  = 'b0000000-0000-4000-b000-000000000001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = app(SessionRepositoryInterface::class);
    }

    private function makeSession(
        string $terminalId  = self::TERMINAL_ID,
        string $cashierId   = self::CASHIER_ID,
        string $fingerprint = 'test-fp-001',
        string $ip          = '10.0.0.1',
    ): Session {
        return Session::open(
            terminalId:  $terminalId,
            cashierId:   $cashierId,
            fingerprint: DeviceFingerprint::of($fingerprint),
            ipAddress:   $ip,
        );
    }

    // ── Basic CRUD ────────────────────────────────────────────────────────────

    public function test_save_and_find_by_id(): void
    {
        $session = $this->makeSession();
        $this->repository->save($session);

        $found = $this->repository->findById($session->id);

        $this->assertNotNull($found);
        $this->assertSame($session->id, $found->id);
        $this->assertSame(SessionStatus::Open, $found->status);
        $this->assertSame(self::TERMINAL_ID, $found->terminal_id);
        $this->assertSame(self::CASHIER_ID, $found->cashier_id);
    }

    public function test_find_by_id_returns_null_for_unknown(): void
    {
        $found = $this->repository->findById('00000000-0000-0000-0000-000000000000');

        $this->assertNull($found);
    }

    // ── Enum round-trip ───────────────────────────────────────────────────────

    public function test_status_enum_persists_correctly(): void
    {
        $session = $this->makeSession();
        $session->suspend();
        $this->repository->save($session);

        $found = $this->repository->findById($session->id);

        $this->assertNotNull($found);
        $this->assertSame(SessionStatus::Suspended, $found->status);
    }

    public function test_device_type_enum_round_trips_through_database(): void
    {
        $session = Session::open(
            terminalId:  self::TERMINAL_ID,
            cashierId:   self::CASHIER_ID,
            fingerprint: DeviceFingerprint::of('fp-mobile'),
            ipAddress:   '1.2.3.4',
            deviceType:  DeviceType::Mobile,
        );
        $this->repository->save($session);

        $found = $this->repository->findById($session->id);

        $this->assertNotNull($found);
        $this->assertSame(DeviceType::Mobile, $found->getDeviceType());
    }

    // ── Open-session queries ──────────────────────────────────────────────────

    public function test_find_open_by_terminal_returns_active_session(): void
    {
        $session = $this->makeSession();
        $this->repository->save($session);

        $found = $this->repository->findOpenByTerminal(self::TERMINAL_ID);

        $this->assertNotNull($found);
        $this->assertSame($session->id, $found->id);
    }

    public function test_find_open_by_terminal_returns_null_when_session_is_closed(): void
    {
        $session = $this->makeSession();
        $session->close();
        $this->repository->save($session);

        $found = $this->repository->findOpenByTerminal(self::TERMINAL_ID);

        $this->assertNull($found);
    }

    public function test_find_open_by_terminal_returns_null_for_unknown_terminal(): void
    {
        $found = $this->repository->findOpenByTerminal('c0000000-0000-0000-0000-000000000099');

        $this->assertNull($found);
    }

    public function test_has_open_session_for_terminal_returns_true(): void
    {
        $session = $this->makeSession();
        $this->repository->save($session);

        $this->assertTrue($this->repository->hasOpenSessionForTerminal(self::TERMINAL_ID));
    }

    public function test_has_open_session_for_terminal_returns_false_after_close(): void
    {
        $session = $this->makeSession();
        $session->close();
        $this->repository->save($session);

        $this->assertFalse($this->repository->hasOpenSessionForTerminal(self::TERMINAL_ID));
    }

    // ── State change persistence ──────────────────────────────────────────────

    public function test_suspend_and_resume_persist_correctly(): void
    {
        $session = $this->makeSession();
        $this->repository->save($session);

        $session->suspend();
        $this->repository->save($session);

        $afterSuspend = $this->repository->findById($session->id);
        $this->assertNotNull($afterSuspend);
        $this->assertSame(SessionStatus::Suspended, $afterSuspend->status);
        $this->assertNotNull($afterSuspend->suspended_at);

        $afterSuspend->resume();
        $this->repository->save($afterSuspend);

        $afterResume = $this->repository->findById($session->id);
        $this->assertNotNull($afterResume);
        $this->assertSame(SessionStatus::Open, $afterResume->status);
        $this->assertNull($afterResume->suspended_at);
    }

    public function test_request_recovery_persists(): void
    {
        $session = $this->makeSession();
        $session->suspend();
        $session->requestRecovery();
        $this->repository->save($session);

        $found = $this->repository->findById($session->id);

        $this->assertNotNull($found);
        $this->assertSame(SessionStatus::RecoveryPending, $found->status);
        $this->assertTrue($found->status->requiresSupervisorReview());
    }

    // ── Partial unique index ───────────────────────────────────────────────────

    public function test_partial_unique_index_prevents_two_open_sessions_for_same_terminal(): void
    {
        $first = $this->makeSession();
        $this->repository->save($first);

        $second = $this->makeSession(fingerprint: 'different-device');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->repository->save($second);
    }

    public function test_closed_session_does_not_block_new_open_session(): void
    {
        $first = $this->makeSession(fingerprint: 'device-1');
        $first->close();
        $this->repository->save($first);

        $second = $this->makeSession(fingerprint: 'device-2');
        $this->repository->save($second);

        $open = $this->repository->findOpenByTerminal(self::TERMINAL_ID);

        $this->assertNotNull($open);
        $this->assertSame($second->id, $open->id);
    }
}
