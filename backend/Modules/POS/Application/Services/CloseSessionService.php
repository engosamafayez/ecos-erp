<?php

declare(strict_types=1);

namespace Modules\POS\Application\Services;

use Modules\POS\Application\Commands\CloseSessionCommand;
use Modules\POS\Application\Contracts\DomainEventPublisherInterface;
use Modules\POS\Application\Exceptions\SessionNotFoundException;
use Modules\POS\Application\Results\CloseSessionResult;
use Modules\POS\Session\Domain\Contracts\SessionRepositoryInterface;
use Modules\POS\Session\Domain\Events\SessionClosed;

final class CloseSessionService
{
    public function __construct(
        private readonly SessionRepositoryInterface    $sessionRepo,
        private readonly DomainEventPublisherInterface $publisher,
    ) {}

    public function execute(CloseSessionCommand $command): CloseSessionResult
    {
        $session = $this->sessionRepo->findById($command->sessionId);

        if ($session === null) {
            throw SessionNotFoundException::withId($command->sessionId);
        }

        $durationMinutes = $session->opened_at
            ? (int) ceil($session->opened_at->diffInMinutes(now()))
            : 0;

        $session->close();
        $this->sessionRepo->save($session);

        $this->publisher->publishAll([
            SessionClosed::now(
                sessionId:       (string) $session->id,
                terminalId:      $session->terminal_id,
                cashierId:       $session->cashier_id,
                durationMinutes: $durationMinutes,
            ),
        ]);

        return new CloseSessionResult((string) $session->id);
    }
}
