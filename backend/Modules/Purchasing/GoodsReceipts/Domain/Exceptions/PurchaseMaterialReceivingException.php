<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Domain\Exceptions;

use App\Core\Exceptions\BusinessException;

/**
 * Refusals specific to receiving against a Purchase Material (Part 1).
 *
 * Every constructor here states a fact the receipt cannot satisfy — it never guesses a
 * substitute. In particular `supplierMissing` fails CLOSED: RD-1 makes
 * `purchase_material_lines.supplier_id` the supplier SSOT for this path, so a line with no
 * supplier has no identity to attribute the stock to, and silently falling back to another
 * source would be exactly the second-source-of-truth this design refuses.
 */
final class PurchaseMaterialReceivingException extends BusinessException
{
    public static function lineNotFound(string $lineId): self
    {
        return new self(
            "Purchase material line [{$lineId}] was not found.",
            ['purchase_material_line_id' => $lineId],
        );
    }

    public static function supplierMissing(string $lineId): self
    {
        return new self(
            "Purchase material line [{$lineId}] has no supplier selected. Select a supplier for the line before receiving against it.",
            ['purchase_material_line_id' => $lineId],
        );
    }

    public static function supplierMismatch(): self
    {
        return new self(
            'All lines on one goods receipt must belong to the same supplier.',
            [],
        );
    }

    public static function mixedAnchors(): self
    {
        return new self(
            'A goods receipt cannot mix purchase-order lines and purchase-material lines.',
            [],
        );
    }

    public static function productMismatch(string $lineId): self
    {
        return new self(
            "The received product does not match purchase material line [{$lineId}].",
            ['purchase_material_line_id' => $lineId],
        );
    }

    public static function crossCompany(string $lineId): self
    {
        return new self(
            "Purchase material line [{$lineId}] belongs to another company.",
            ['purchase_material_line_id' => $lineId],
        );
    }

    public static function overReceipt(string $lineId, float $required, float $alreadyReceived, float $nowReceiving): self
    {
        $wouldTotal = round($alreadyReceived + $nowReceiving, 4);

        return new self(
            "Over-receipt on purchase material line [{$lineId}]: required {$required}, already received {$alreadyReceived}, ".
            "now receiving {$nowReceiving} — total would be {$wouldTotal}.",
            [
                'purchase_material_line_id' => $lineId,
                'required_qty' => $required,
                'already_received' => $alreadyReceived,
                'now_receiving' => $nowReceiving,
                'would_total' => $wouldTotal,
            ],
        );
    }

    /** @param array<string, mixed> $errors */
    private function __construct(string $message, array $errors)
    {
        parent::__construct(message: $message, errors: $errors, statusCode: 422);
    }
}
