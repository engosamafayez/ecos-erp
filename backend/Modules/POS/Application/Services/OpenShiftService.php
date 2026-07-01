<?php

declare(strict_types=1);

namespace Modules\POS\Application\Services;

use Modules\POS\Application\Commands\OpenShiftCommand;
use Modules\POS\Application\Contracts\DomainEventPublisherInterface;
use Modules\POS\Application\Exceptions\SessionNotFoundException;
use Modules\POS\Application\Exceptions\ShiftAlreadyOpenException;
use Modules\POS\Application\Results\OpenShiftResult;
use Modules\POS\Session\Domain\Contracts\SessionRepositoryInterface;
use Modules\POS\Shift\Domain\Contracts\ShiftRepositoryInterface;
use Modules\POS\Shift\Domain\Events\ShiftOpened;
use Modules\POS\Shift\Domain\Models\Shift;
use Modules\POS\Shift\Domain\ValueObjects\ShiftNumber;
use Modules\POS\Shared\Domain\ValueObjects\Money;

final class OpenShiftService
{
    public function __construct(
        private readonly SessionRepositoryInterface    $sessionRepo,
        private readonly ShiftRepositoryInterface      $shiftRepo,
        private readonly DomainEventPublisherInterface $publisher,
    ) {}

    public function execute(OpenShiftCommand $command): OpenShiftResult
    {
        $session = $this->sessionRepo->findById($command->sessionId);

        if ($session === null) {
            throw SessionNotFoundException::withId($command->sessionId);
        }

        $existingShift = $this->shiftRepo->findOpenBySession($command->sessionId);

        if ($existingShift !== null) {
            throw ShiftAlreadyOpenException::forSession($command->sessionId);
        }

        $shiftCount  = $this->shiftRepo->countByTerminal($command->terminalId);
        $shiftNumber = ShiftNumber::of($shiftCount + 1);
        $openingCash = Money::of($command->openingCashAmount, $command->openingCashCurrency);

        $shift = Shift::open(
            sessionId:   $command->sessionId,
            terminalId:  $command->terminalId,
            cashierId:   $command->cashierId,
            openingCash: $openingCash,
            shiftNumber: $shiftNumber,
        );

        $this->shiftRepo->save($shift);

        $this->publisher->publishAll([
            ShiftOpened::now(
                shiftId:           (string) $shift->id,
                sessionId:         $shift->session_id,
                terminalId:        $shift->terminal_id,
                cashierId:         $shift->cashier_id,
                shiftNumber:       $shift->shift_number,
                openingCashAmount: $openingCash->amount,
                currency:          $openingCash->currency,
            ),
        ]);

        return new OpenShiftResult((string) $shift->id, $shift->shift_number);
    }
}
