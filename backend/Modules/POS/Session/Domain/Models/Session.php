<?php

declare(strict_types=1);

namespace Modules\POS\Session\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\POS\Session\Domain\Enums\DeviceType;
use Modules\POS\Session\Domain\Exceptions\InvalidSessionTransitionException;
use Modules\POS\Session\Domain\ValueObjects\DeviceFingerprint;
use Modules\POS\Shared\Domain\Enums\SessionStatus;

final class Session extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'pos_sessions';

    protected $fillable = [
        'terminal_id',
        'cashier_id',
        'status',
        'device_fingerprint',
        'device_type',
        'ip_address',
        'opened_at',
        'suspended_at',
        'closed_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status'       => SessionStatus::class,
            'device_type'  => DeviceType::class,
            'metadata'     => 'array',
            'opened_at'    => 'datetime',
            'suspended_at' => 'datetime',
            'closed_at'    => 'datetime',
        ];
    }

    /**
     * Opens a new cashier session on the given terminal.
     *
     * The caller is responsible for first checking hasOpenSessionForTerminal()
     * to ensure no conflicting Open session exists for the terminal.
     * The returned session is NOT persisted — call $repository->save() after.
     */
    public static function open(
        string            $terminalId,
        string            $cashierId,
        DeviceFingerprint $fingerprint,
        string            $ipAddress,
        DeviceType        $deviceType = DeviceType::Browser,
    ): self {
        if (trim($terminalId) === '') {
            throw new \InvalidArgumentException('Terminal ID cannot be empty.');
        }
        if (trim($cashierId) === '') {
            throw new \InvalidArgumentException('Cashier ID cannot be empty.');
        }

        $session                     = new self();
        $session->terminal_id        = $terminalId;
        $session->cashier_id         = $cashierId;
        $session->status             = SessionStatus::Open;
        $session->device_fingerprint = $fingerprint->value;
        $session->device_type        = $deviceType;
        $session->ip_address         = $ipAddress;
        $session->opened_at          = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        return $session;
    }

    /** Suspends an Open session (e.g. terminal lock, cashier walk-away). */
    public function suspend(): void
    {
        if ($this->status !== SessionStatus::Open) {
            throw InvalidSessionTransitionException::cannotTransition(
                (string) $this->id,
                $this->status,
                SessionStatus::Suspended,
            );
        }

        $this->status       = SessionStatus::Suspended;
        $this->suspended_at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    /**
     * Moves a Suspended session to RecoveryPending when a different device
     * attempts to resume it. The session waits for supervisor approval. (ADR-POS-008)
     */
    public function requestRecovery(): void
    {
        if ($this->status !== SessionStatus::Suspended) {
            throw InvalidSessionTransitionException::cannotTransition(
                (string) $this->id,
                $this->status,
                SessionStatus::RecoveryPending,
            );
        }

        $this->status = SessionStatus::RecoveryPending;
    }

    /**
     * Resumes a Suspended or RecoveryPending session back to Open.
     * For RecoveryPending, the caller must obtain supervisor approval before calling this.
     */
    public function resume(): void
    {
        if (!in_array($this->status, [SessionStatus::Suspended, SessionStatus::RecoveryPending], true)) {
            throw InvalidSessionTransitionException::cannotTransition(
                (string) $this->id,
                $this->status,
                SessionStatus::Open,
            );
        }

        $this->status       = SessionStatus::Open;
        $this->suspended_at = null;
    }

    /** Closes the session from any non-terminal state. */
    public function close(): void
    {
        if ($this->status === SessionStatus::Closed) {
            throw InvalidSessionTransitionException::alreadyInState(
                (string) $this->id,
                SessionStatus::Closed,
            );
        }

        $this->status    = SessionStatus::Closed;
        $this->closed_at = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public function getDeviceFingerprint(): DeviceFingerprint
    {
        return DeviceFingerprint::of($this->device_fingerprint);
    }

    public function getDeviceType(): DeviceType
    {
        return $this->device_type;
    }

    /** Returns true when the given fingerprint matches this session's originating device. */
    public function isSameDevice(DeviceFingerprint $fingerprint): bool
    {
        return $this->getDeviceFingerprint()->equals($fingerprint);
    }

    public function isOpen(): bool
    {
        return $this->status === SessionStatus::Open;
    }

    public function isClosed(): bool
    {
        return $this->status === SessionStatus::Closed;
    }

    /** True when the cashier can process transactions (Open state only). */
    public function canTransact(): bool
    {
        return $this->status->canTransact();
    }

    /** True for Open, Suspended, and RecoveryPending states. */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
