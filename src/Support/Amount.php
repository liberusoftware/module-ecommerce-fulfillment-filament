<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Support;

use Liberu\Ecommerce\Fulfillment\Models\Shipment;

/**
 * What carriage cost, rendered from the integer the database holds.
 *
 * **No float ever appears here.** {@see decimal()} is string arithmetic — pad,
 * split, concatenate — and the whole reason it is written out rather than done
 * with a division is that `(int) (19.99 * 100)` is `1998` and `1999 / 100` is
 * where the penny goes. A column that formatted with
 * `number_format($minor / 100, 2)` would agree with this on nearly every amount,
 * which is exactly what makes it dangerous.
 *
 * The exponent travels with the amount rather than being assumed to be two,
 * because the parcel stores it: a zero-exponent currency rendered as
 * `1999 → 19.99` has divided somebody's carriage bill by a hundred.
 *
 * Prefixed with the ISO code rather than a symbol. A panel serving several
 * merchants shows several currencies in one column, two symbols do not tell a
 * screen reader which is which, and this package ships no currency table to look
 * a symbol up in.
 *
 * A carriage cost is **not a charge to the customer**. What the shopper paid for
 * delivery is a line on their order, priced before anybody decided what goes in
 * which box, and it is not this module's to show.
 */
final class Amount
{
    public static function format(int $minor, string $currency, int $exponent = 2): string
    {
        return $currency.' '.self::decimal($minor, $exponent);
    }

    /**
     * An optional amount on a parcel, in the currency that parcel was recorded
     * with, or null when nothing was recorded.
     *
     * Null rather than a zero: `0` and "nobody told us" are different facts, and
     * a carriage cost of zero is a claim about a free delivery.
     */
    public static function of(Shipment $shipment, ?int $minor): ?string
    {
        if ($minor === null) {
            return null;
        }

        $currency = $shipment->currency;

        // The domain refuses an amount with no currency on the way in, so this
        // branch is for a row that predates that guard or was written round it.
        // A bare number is honest; a guessed currency is not.
        return $currency === null || $currency === ''
            ? self::decimal($minor, $shipment->currency_exponent)
            : self::format($minor, $currency, $shipment->currency_exponent);
    }

    /** Integer minor units as a decimal string, by string arithmetic only. */
    public static function decimal(int $minor, int $exponent = 2): string
    {
        if ($exponent <= 0) {
            return (string) $minor;
        }

        $digits = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '')
            .substr($digits, 0, -$exponent)
            .'.'
            .substr($digits, -$exponent);
    }
}
