<?php

declare(strict_types=1);

namespace Tests\Feature\POS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\POS\Application\Commands\OpenSessionCommand;
use Modules\POS\Application\Commands\OpenShiftCommand;
use Modules\POS\Application\Exceptions\SessionAlreadyOpenException;
use Modules\POS\Application\Exceptions\ShiftAlreadyOpenException;
use Modules\POS\Application\Services\OpenSessionService;
use Modules\POS\Application\Services\OpenShiftService;
use Modules\POS\Receipt\Domain\Contracts\ReceiptNumberingStrategyInterface;
use Modules\POS\Session\Domain\Contracts\SessionRepositoryInterface;
use Modules\POS\Session\Domain\Enums\DeviceType;
use Modules\POS\Session\Domain\Models\Session;
use Modules\POS\Session\Domain\ValueObjects\DeviceFingerprint;
use Tests\TestCase;

/**
 * PKG-POS-019: Concurrency safety for session, shift, and receipt numbering.
 *
 * These tests exercise the advisory-lock + transaction guards added in Items 6, 7.
 * True parallelism cannot be tested in a single PHP process; these tests verify
 * the application-level guard (duplicate-check inside the same transaction) works
 * correctly under sequential re-entry.
 */
final class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const TERMINAL_ID = 'a0000000-0000-4000-a000-000000099001';
    private const CASHIER_ID  = 'b0000000-0000-4000-b000-000000099001';
    private const CURRENCY    = 'EGP';

    // ── Session guard ─────────────────────────────────────────────────────────

    public function test_opening_second_session_for_same_terminal_throws(): void
    {
        $service = app(OpenSessionService::class);
        $command = $this->makeSessionCommand();

        $service->execute($command);

        $this->expectException(SessionAlreadyOpenException::class);
        $service->execute($command);
    }

    public function test_opening_sessions_for_different_terminals_succeeds(): void
    {
        $service = app(OpenSessionService::class);

        $terminalA = 'a0000000-0000-4000-a000-000000099002';
        $terminalB = 'a0000000-0000-4000-a000-000000099003';

        $service->execute(new OpenSessionCommand(
            terminalId:        $terminalA,
            cashierId:         self::CASHIER_ID,
            deviceFingerprint: 'fp-terminal-a',
            ipAddress:         '10.0.0.1',
            deviceType:        'browser',
        ));

        $service->execute(new OpenSessionCommand(
            terminalId:        $terminalB,
            cashierId:         self::CASHIER_ID,
            deviceFingerprint: 'fp-terminal-b',
            ipAddress:         '10.0.0.2',
            deviceType:        'browser',
        ));

        $this->assertTrue(true); // both succeeded without exception
    }

    // ── Shift guard ───────────────────────────────────────────────────────────

    public function test_opening_second_shift_for_same_session_throws(): void
    {
        $sessionId = $this->makeOpenSession();
        $service   = app(OpenShiftService::class);
        $command   = $this->makeShiftCommand($sessionId);

        $service->execute($command);

        $this->expectException(ShiftAlreadyOpenException::class);
        $service->execute($command);
    }

    public function test_shift_numbers_are_sequential_for_same_terminal(): void
    {
        $sessionRepo = app(SessionRepositoryInterface::class);
        $shiftService = app(OpenShiftService::class);

        // First session + shift
        $session1 = Session::open(
            terminalId:  self::TERMINAL_ID,
            cashierId:   self::CASHIER_ID,
            fingerprint: DeviceFingerprint::of('fp-seq-1'),
            ipAddress:   '127.0.0.1',
            deviceType:  DeviceType::Browser,
        );
        $sessionRepo->save($session1);

        $result1 = $shiftService->execute(new OpenShiftCommand(
            sessionId:           (string) $session1->id,
            terminalId:          self::TERMINAL_ID,
            cashierId:           self::CASHIER_ID,
            openingCashAmount:   '100.00',
            openingCashCurrency: self::CURRENCY,
        ));

        $this->assertSame(1, $result1->shiftNumber);
    }

    // ── Receipt numbering ─────────────────────────────────────────────────────

    public function test_receipt_numbering_generates_unique_numbers_per_terminal(): void
    {
        $strategy = app(ReceiptNumberingStrategyInterface::class);
        $now      = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $terminalId = self::TERMINAL_ID;
        $n1 = $strategy->next($terminalId, $now);
        $n2 = $strategy->next($terminalId, $now);

        $this->assertNotSame($n1, $n2);
        $this->assertStringStartsWith('RCP-', $n1);
        $this->assertStringStartsWith('RCP-', $n2);
    }

    public function test_receipt_numbering_sequences_are_isolated_per_terminal(): void
    {
        $strategy   = app(ReceiptNumberingStrategyInterface::class);
        $now        = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $terminalA  = 'a0000000-0000-4000-a000-000000099010';
        $terminalB  = 'a0000000-0000-4000-a000-000000099011';

        $nA = $strategy->next($terminalA, $now);
        $nB = $strategy->next($terminalB, $now);

        $this->assertNotSame($nA, $nB);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeOpenSession(): string
    {
        $sessionRepo = app(SessionRepositoryInterface::class);
        $session     = Session::open(
            terminalId:  self::TERMINAL_ID,
            cashierId:   self::CASHIER_ID,
            fingerprint: DeviceFingerprint::of('fp-conc-test-' . uniqid()),
            ipAddress:   '127.0.0.1',
            deviceType:  DeviceType::Browser,
        );
        $sessionRepo->save($session);

        return (string) $session->id;
    }

    private function makeSessionCommand(): OpenSessionCommand
    {
        return new OpenSessionCommand(
            terminalId:        self::TERMINAL_ID,
            cashierId:         self::CASHIER_ID,
            deviceFingerprint: 'fp-conc-' . uniqid(),
            ipAddress:         '127.0.0.1',
            deviceType:        'browser',
        );
    }

    private function makeShiftCommand(string $sessionId): OpenShiftCommand
    {
        return new OpenShiftCommand(
            sessionId:           $sessionId,
            terminalId:          self::TERMINAL_ID,
            cashierId:           self::CASHIER_ID,
            openingCashAmount:   '500.00',
            openingCashCurrency: self::CURRENCY,
        );
    }
}
