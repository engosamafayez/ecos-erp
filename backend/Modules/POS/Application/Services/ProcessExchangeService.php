<?php

declare(strict_types=1);

namespace Modules\POS\Application\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\POS\Application\Commands\ProcessExchangeCommand;
use Modules\POS\Application\Contracts\DomainEventPublisherInterface;
use Modules\POS\Application\Exceptions\SaleNotFoundException;
use Modules\POS\Application\Results\ProcessExchangeResult;
use Modules\POS\Exchange\Domain\Contracts\ExchangeRepositoryInterface;
use Modules\POS\Exchange\Domain\Enums\ExchangeReason;
use Modules\POS\Exchange\Domain\Models\Exchange;
use Modules\POS\Exchange\Domain\ValueObjects\ExchangeLine;
use Modules\POS\Receipt\Domain\Contracts\ReceiptNumberingStrategyInterface;
use Modules\POS\Receipt\Domain\Contracts\ReceiptRepositoryInterface;
use Modules\POS\Receipt\Domain\Enums\ReceiptType;
use Modules\POS\Receipt\Domain\Models\Receipt;
use Modules\POS\Receipt\Domain\ValueObjects\ReceiptLineItem;
use Modules\POS\Receipt\Domain\ValueObjects\ReceiptPayment;
use Modules\POS\Receipt\Domain\ValueObjects\ReceiptTotals;
use Modules\POS\Sale\Domain\Contracts\SaleRepositoryInterface;
use Modules\POS\Shared\Domain\ValueObjects\Money;

final class ProcessExchangeService
{
    public function __construct(
        private readonly SaleRepositoryInterface          $saleRepo,
        private readonly ExchangeRepositoryInterface      $exchangeRepo,
        private readonly ReceiptRepositoryInterface       $receiptRepo,
        private readonly ReceiptNumberingStrategyInterface $receiptNumbering,
        private readonly DomainEventPublisherInterface    $publisher,
    ) {}

    public function execute(ProcessExchangeCommand $command): ProcessExchangeResult
    {
        $sale = $this->saleRepo->findById($command->originalSaleId);

        if ($sale === null) {
            throw SaleNotFoundException::withId($command->originalSaleId);
        }

        $receiptNumber = $this->receiptNumbering->next(
            $command->terminalId,
            new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );

        $exchange = null;
        $receipt  = null;

        DB::transaction(function () use ($command, $receiptNumber, &$exchange, &$receipt) {
            $returnedLines = array_map(
                fn(array $line) => ExchangeLine::fromArray($line),
                $command->returnedLines,
            );

            $replacementLines = array_map(
                fn(array $line) => ExchangeLine::fromArray($line),
                $command->replacementLines,
            );

            $exchange = Exchange::initiate(
                exchangeNumber:   $command->exchangeNumber,
                originalSaleId:   $command->originalSaleId,
                originalSaleNumber: $command->originalSaleNumber,
                terminalId:       $command->terminalId,
                sessionId:        $command->sessionId,
                shiftId:          $command->shiftId,
                cashierId:        $command->cashierId,
                customerId:       $command->customerId,
                currency:         $command->currency,
                returnedLines:    $returnedLines,
                replacementLines: $replacementLines,
                reason:           ExchangeReason::from($command->reason),
                notes:            $command->notes,
            );

            $exchange->confirm();
            $exchange->complete();
            $this->exchangeRepo->save($exchange);

            $returnedTotal     = $exchange->getReturnedTotal();
            $replacementTotal  = $exchange->getReplacementTotal();
            $valueDiff         = $exchange->getValueDifference();

            $receiptLineItems = [];

            foreach ($returnedLines as $i => $rl) {
                $receiptLineItems[] = ReceiptLineItem::of(
                    productId:       $rl->productId,
                    productName:     '(Return) ' . $rl->productName,
                    sku:             $rl->sku,
                    quantityValue:   $rl->quantity->value,
                    unitPriceAmount: $rl->unitPrice->amount,
                    lineTotalAmount: $rl->lineTotal->amount,
                    currency:        $rl->lineTotal->currency,
                );
            }

            foreach ($replacementLines as $i => $rl) {
                $receiptLineItems[] = ReceiptLineItem::of(
                    productId:       $rl->productId,
                    productName:     $rl->productName,
                    sku:             $rl->sku,
                    quantityValue:   $rl->quantity->value,
                    unitPriceAmount: $rl->unitPrice->amount,
                    lineTotalAmount: $rl->lineTotal->amount,
                    currency:        $rl->lineTotal->currency,
                );
            }

            $netAmount    = $valueDiff->absolute();
            $tenderedAmt  = $valueDiff->isNegative() ? $netAmount->amount : '0.00';
            $changeAmt    = $valueDiff->isPositive() ? $netAmount->amount : '0.00';

            $receiptTotals = ReceiptTotals::of(
                subtotalAmount: $replacementTotal->amount,
                discountAmount: '0.00',
                taxAmount:      '0.00',
                totalAmount:    $replacementTotal->amount,
                tenderedAmount: $tenderedAmt,
                changeAmount:   $changeAmt,
                currency:       $command->currency,
            );

            $receiptPayments = [
                ReceiptPayment::of('exchange', $replacementTotal->amount, $command->currency),
            ];

            $receipt = Receipt::issue(
                receiptNumber:             $receiptNumber,
                type:                      ReceiptType::Exchange,
                originalTransactionId:     (string) $exchange->id,
                originalTransactionNumber: $command->exchangeNumber,
                terminalId:                $command->terminalId,
                sessionId:                 $command->sessionId,
                shiftId:                   $command->shiftId,
                cashierId:                 $command->cashierId,
                cashierName:               $command->cashierName,
                customerId:                $command->customerId,
                customerName:              $command->customerName,
                currency:                  $command->currency,
                lineItems:                 $receiptLineItems,
                totals:                    $receiptTotals,
                payments:                  $receiptPayments,
                issuedAt:                  new DateTimeImmutable('now', new DateTimeZone('UTC')),
            );

            $this->receiptRepo->save($receipt);
        });

        $exchangeEvents = $exchange->pullDomainEvents();
        $receiptEvents  = $receipt->pullDomainEvents();

        $this->publisher->publishAll(array_merge($exchangeEvents, $receiptEvents));

        return new ProcessExchangeResult(
            exchangeId:     (string) $exchange->id,
            exchangeNumber: $command->exchangeNumber,
            receiptId:      (string) $receipt->id,
            receiptNumber:  $receiptNumber,
        );
    }
}
