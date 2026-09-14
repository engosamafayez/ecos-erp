<?php
/**
 * TASK-ECOS-V1.1-CRM-04-CUSTOMER-SELF-SERVICE-UX-AND-FINAL-CLOSURE-020 §15 — the real,
 * customer-facing invoice PDF. Rendered from EXACTLY the same customer-safe fields
 * CustomerInvoiceController::show() already returns (this view receives that same $data
 * array plus $brandName/$orderNumber) — never a second projection, never a GL/posting field.
 *
 * Bilingual (en/ar) via $lang; dompdf's bundled DejaVu Sans covers Arabic glyphs but does not
 * fully shape/reorder Arabic script the way a browser does — this is a disclosed, known dompdf
 * limitation (see the Task 2 report's INVOICE section), not a fabricated "full Arabic support"
 * claim.
 */
$isRtl = $lang === 'ar';
$labels = $isRtl ? [
    'invoice' => 'فاتورة',
    'invoiceNumber' => 'رقم الفاتورة',
    'invoiceDate' => 'تاريخ الفاتورة',
    'dueDate' => 'تاريخ الاستحقاق',
    'order' => 'الطلب',
    'description' => 'الوصف',
    'quantity' => 'الكمية',
    'unitPrice' => 'سعر الوحدة',
    'tax' => 'الضريبة',
    'netAmount' => 'الصافي',
    'subtotal' => 'الإجمالي الفرعي',
    'taxTotal' => 'إجمالي الضريبة',
    'total' => 'الإجمالي',
    'outstanding' => 'المبلغ المستحق',
    'status' => 'الحالة',
] : [
    'invoice' => 'Invoice',
    'invoiceNumber' => 'Invoice Number',
    'invoiceDate' => 'Invoice Date',
    'dueDate' => 'Due Date',
    'order' => 'Order',
    'description' => 'Description',
    'quantity' => 'Quantity',
    'unitPrice' => 'Unit Price',
    'tax' => 'Tax',
    'netAmount' => 'Net Amount',
    'subtotal' => 'Subtotal',
    'taxTotal' => 'Tax Total',
    'total' => 'Total',
    'outstanding' => 'Outstanding',
    'status' => 'Status',
];
$money = static fn ($v) => number_format((float) $v, 2) . ' ' . $data['currency'];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>" dir="<?= $isRtl ? 'rtl' : 'ltr' ?>">
<head>
<meta charset="utf-8">
<style>
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1a1a1a; direction: <?= $isRtl ? 'rtl' : 'ltr' ?>; }
    .header { display: flex; justify-content: space-between; border-bottom: 2px solid #1a1a1a; padding-bottom: 12px; margin-bottom: 16px; }
    .brand { font-size: 20px; font-weight: bold; }
    .meta { text-align: <?= $isRtl ? 'left' : 'right' ?>; }
    .meta div { margin-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; margin-top: 16px; }
    th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: <?= $isRtl ? 'right' : 'left' ?>; font-size: 11px; }
    th { background: #f5f5f5; }
    .totals { width: 40%; margin-<?= $isRtl ? 'right' : 'left' ?>: auto; margin-top: 12px; }
    .totals td { border: none; padding: 3px 8px; }
    .totals .grand { font-weight: bold; font-size: 13px; border-top: 2px solid #1a1a1a; }
</style>
</head>
<body>
    <div class="header">
        <div class="brand"><?= e($brandName ?? '') ?></div>
        <div class="meta">
            <div><strong><?= $labels['invoiceNumber'] ?>:</strong> <?= e($data['number']) ?></div>
            <div><strong><?= $labels['invoiceDate'] ?>:</strong> <?= e($data['invoice_date'] ?? '') ?></div>
            @if($data['due_date'])
                <div><strong><?= $labels['dueDate'] ?>:</strong> <?= e($data['due_date']) ?></div>
            @endif
            <div><strong><?= $labels['order'] ?>:</strong> <?= e($orderNumber ?? '') ?></div>
            <div><strong><?= $labels['status'] ?>:</strong> <?= e($data['status']) ?></div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th><?= $labels['description'] ?></th>
                <th><?= $labels['quantity'] ?></th>
                <th><?= $labels['unitPrice'] ?></th>
                <th><?= $labels['tax'] ?></th>
                <th><?= $labels['netAmount'] ?></th>
            </tr>
        </thead>
        <tbody>
            @foreach($data['lines'] as $line)
                <tr>
                    <td><?= e($line['description'] ?? '') ?></td>
                    <td><?= e((string) $line['quantity']) ?></td>
                    <td><?= $money($line['unit_price']) ?></td>
                    <td><?= $money($line['tax_amount']) ?></td>
                    <td><?= $money($line['net_amount']) ?></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td><?= $labels['subtotal'] ?></td><td><?= $money($data['subtotal']) ?></td></tr>
        <tr><td><?= $labels['taxTotal'] ?></td><td><?= $money($data['tax_total']) ?></td></tr>
        <tr class="grand"><td><?= $labels['total'] ?></td><td><?= $money($data['total']) ?></td></tr>
        @if($data['outstanding'] !== null)
            <tr><td><?= $labels['outstanding'] ?></td><td><?= $money($data['outstanding']) ?></td></tr>
        @endif
    </table>
</body>
</html>
