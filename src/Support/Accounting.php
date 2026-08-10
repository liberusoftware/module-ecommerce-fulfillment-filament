<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Support;

use Liberu\Ecommerce\Fulfillment\Models\FulfillmentLine;
use Liberu\Ecommerce\Fulfillment\Models\FulfillmentRequest;

/**
 * Where a line of demand has got to, said in words.
 *
 * **There is no status on a line, and this is why the panel needs a sentence for
 * it.** A line of five can be two dispatched, one sitting in a packed parcel, one
 * cancelled and one still to pick at the same instant, and no single word says
 * that. The domain keeps three counters instead of a status for exactly that
 * reason, so a panel that showed one word would be hiding the thing an operator
 * came to find out.
 *
 * Words rather than a bar or a colour. A progress bar has no accessible value
 * unless somebody remembers to give it one, and "amber" is not a quantity.
 *
 * **The two derived counts are the domain's own.** `remainingQuantity()` is
 * `quantity − committed − cancelled` and `undispatchedQuantity()` is
 * `committed − dispatched`; both are published so that no consumer derives one
 * and gets it wrong, and this is a consumer. Nothing here subtracts.
 *
 * The order the phrases come in is the order the goods move: still to pick, then
 * packed and waiting, then gone, then never going.
 */
final class Accounting
{
    /** One line's accounting, as a phrase. */
    public static function ofLine(FulfillmentLine $line): string
    {
        return self::describe(
            $line->remainingQuantity(),
            $line->undispatchedQuantity(),
            $line->dispatched_quantity,
            $line->cancelled_quantity,
        );
    }

    /**
     * Everything an order owes, as a phrase.
     *
     * Summed over the lines currently loaded.
     * `FulfillmentRequestResource::getEloquentQuery()` eager-loads them, so this
     * is arithmetic over an existing collection rather than a query per row.
     */
    public static function ofRequest(FulfillmentRequest $request): string
    {
        if ($request->lines->isEmpty()) {
            // The domain allows a request with no lines at all: an order whose
            // every line was already cancelled asks for nothing.
            return 'Nothing was asked for';
        }

        return self::describe(
            $request->lines->sum(fn (FulfillmentLine $line): int => $line->remainingQuantity()),
            $request->lines->sum(fn (FulfillmentLine $line): int => $line->undispatchedQuantity()),
            $request->lines->sum('dispatched_quantity'),
            $request->lines->sum('cancelled_quantity'),
        );
    }

    /** What is still to put in a box, across every line of a request. */
    public static function remainingOf(FulfillmentRequest $request): int
    {
        return (int) $request->lines->sum(fn (FulfillmentLine $line): int => $line->remainingQuantity());
    }

    private static function describe(int $remaining, int $packed, int $dispatched, int $cancelled): string
    {
        $parts = [];

        if ($remaining > 0) {
            $parts[] = $remaining.' to pick';
        }

        if ($packed > 0) {
            $parts[] = $packed.' packed and waiting';
        }

        if ($dispatched > 0) {
            $parts[] = $dispatched.' dispatched';
        }

        if ($cancelled > 0) {
            $parts[] = $cancelled.' cancelled';
        }

        // Every count zero means a line of nothing, and an empty cell cannot be
        // told apart from a column that failed to render.
        return $parts === [] ? 'Nothing to account for' : implode(', ', $parts);
    }
}
