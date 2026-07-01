<?php

declare(strict_types=1);

namespace Modules\POS\Application\Services;

use Modules\POS\Application\Commands\CloseShiftCommand;
use Modules\POS\Application\Contracts\DomainEventPublisherInterface;
use Modules\POS\Application\Exceptions\ShiftNotFoundException;
use Modules\POS\Application\Results\CloseShiftResult;
use Modules\POS\Shift\Domain\Contracts\ShiftRepositoryInterface;
use Modules\POS\Shift\Domain\Events\ShiftSubmittedForClosure;
use Modules\POS\Shared\Domain\ValueObjects\Money;

final class CloseShiftService
{
    public function __construct(
        private readonly ShiftRepositoryInterface      $shiftRepo,
        private readonly DomainEventPublisherInterface $publisher,
    ) {}

    public function execute(CloseShiftCommand $command): CloseShiftResult
    {
        $shift = $this->shiftRepo->findById($command->shiftId);

        if ($shift === null) {
            throw ShiftNotFoundException::withId($command->shiftId);
        }

        $closingCount = Money::of($command->closingCountAmount, $command->closingCountCurrency);

        $shift->submitForClosure($closingCount);
        $this->shiftRepo->save($shift);

        $this->publisher->publishAll([
            ShiftSubmittedForClosure::now(
                shiftId:            (string) $shift->id,
                sessionId:          $shift->session_id,
                terminalId:         $shift->terminal_id,
                cashierId:          $shift->cashier_id,
                shiftNumber:        $shift->shift_number,
                closingCountAmount: $closingCount->amount,
                currency:           $closingCount->currency,
            ),
        ]);

        return new CloseShiftResult((string) $shift->id);
    }
}
