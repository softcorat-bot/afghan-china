<?php

namespace App\Services\Sales;

use App\Models\Sale;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\QueryException;

/**
 * Company-wide invoice numbering.
 *
 * Extracted so that the live web checkout and the offline sync engine draw
 * numbers from exactly one place: the sequence is company-wide (that is what the
 * unique index covers), so it must ignore the active-branch scope — otherwise a
 * cashier in branch B redraws a number branch A already used, and no amount of
 * retrying helps because the scoped maximum never moves.
 */
class InvoiceNumbers
{
    public function next(int $companyId): string
    {
        $last = Sale::withTrashed()
            ->withoutGlobalScope(BranchScope::class)
            ->where('company_id', $companyId)
            ->orderByDesc('id')->lockForUpdate()->value('invoice_no');

        $n = 0;
        if ($last && preg_match('/(\d+)$/', $last, $m)) {
            $n = (int) $m[1];
        }

        return 'INV-'.str_pad((string) ($n + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Run a write that consumes an invoice number; if two writers draw the same
     * number, the unique index rejects the copy and the write is retried with a
     * fresh one. Nothing is lost, nothing is duplicated.
     */
    public function withRetry(callable $callback, int $attempts = 5): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $callback();
            } catch (QueryException $e) {
                $duplicateInvoice = str_contains($e->getMessage(), 'invoice_no')
                    && in_array((string) $e->getCode(), ['23000', '23505'], true);

                if (! $duplicateInvoice || $try >= $attempts) {
                    throw $e;
                }
            }
        }
    }
}
