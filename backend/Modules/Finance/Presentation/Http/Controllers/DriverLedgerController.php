<?php

declare(strict_types=1);

namespace Modules\Finance\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Modules\Finance\OperationalCost\Domain\Models\DriverLedgerEntry;
use Modules\Finance\Presentation\Http\Controllers\Concerns\ResolvesFinanceContext;

/**
 * The Driver financial subledger read surface (TASK-ECOS-FINANCE-UX-
 * REPORTING-CLOSURE-008) — the accounting-data authority Task 7's own
 * DriverFinanceService/DriverLedgerEntry already established, exposed for a
 * Driver Statement / accounting-activity view. Mirrors CustomerLedgerController's
 * history()/balance() shape; no "aging" concept applies to a driver subledger,
 * so it is not replicated here.
 */
class DriverLedgerController extends Controller
{
    use ResolvesFinanceContext;

    public function history(Request $request, string $driverId): JsonResponse
    {
        $companyId = $this->companyId($request);
        $from = $request->filled('from') ? Carbon::parse($request->string('from')) : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')) : null;

        $entries = DriverLedgerEntry::query()
            ->where('company_id', $companyId)
            ->where('driver_id', $driverId)
            ->when($from !== null, fn ($q) => $q->where('entry_date', '>=', $from->toDateString()))
            ->when($to !== null, fn ($q) => $q->where('entry_date', '<=', $to->toDateString()))
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get();

        $running = 0.0;
        $rows = $entries->map(function (DriverLedgerEntry $e) use (&$running): array {
            $running = round($running + (float) $e->amount, 4);

            return [
                'id' => $e->uuid,
                'entry_date' => $e->entry_date?->toDateString(),
                'entry_type' => $e->entry_type->value,
                'amount' => (float) $e->amount,
                'running_balance' => $running,
                'source_type' => $e->source_type,
                'source_id' => $e->source_id,
                'journal_entry_id' => $e->journalEntry?->uuid,
                'description' => $e->description,
            ];
        });

        return response()->json(['data' => [
            'driver_id' => $driverId,
            'balance' => DriverLedgerEntry::balanceFor($companyId, $driverId),
            'entries' => $rows,
        ]]);
    }

    public function balance(Request $request, string $driverId): JsonResponse
    {
        return response()->json(['data' => [
            'driver_id' => $driverId,
            'balance' => DriverLedgerEntry::balanceFor($this->companyId($request), $driverId),
        ]]);
    }
}
