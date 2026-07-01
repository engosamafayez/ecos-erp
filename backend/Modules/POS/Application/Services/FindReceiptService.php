<?php

declare(strict_types=1);

namespace Modules\POS\Application\Services;

use Modules\POS\Application\Exceptions\ReceiptNotFoundException;
use Modules\POS\Receipt\Domain\Contracts\ReceiptRepositoryInterface;
use Modules\POS\Receipt\Domain\Models\Receipt;

final class FindReceiptService
{
    public function __construct(
        private readonly ReceiptRepositoryInterface $receiptRepo,
    ) {}

    public function execute(string $receiptId): Receipt
    {
        $receipt = $this->receiptRepo->findById($receiptId);

        if ($receipt === null) {
            throw ReceiptNotFoundException::withId($receiptId);
        }

        return $receipt;
    }
}
