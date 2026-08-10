<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Support;

use Illuminate\Support\Str;

/**
 * A destination, rendered as labelled lines.
 *
 * The shape belongs to whoever collected it. The domain stores a destination
 * whole as JSON and validates none of its fields, on purpose — address shape is a
 * per-country problem with a long tail, and a package with an opinion about it
 * releases every time a country disagrees. The consequence here is that this
 * cannot assume `line1`, `city`, `postcode`: it gets whatever arrived with the
 * request, and it labels the keys it was given.
 *
 * Labelled rather than run together, because a column of unlabelled strings is
 * announced by a screen reader as one long sentence and read by anybody else by
 * guessing from shape.
 *
 * A value that is not a scalar is dropped rather than rendered: a nested array
 * reaches a text entry as the word `Array`, which is worse than an absent line.
 */
final class Address
{
    /**
     * @param  array<string, mixed>|null  $address
     * @return list<string>
     */
    public static function lines(?array $address): array
    {
        if ($address === null) {
            return [];
        }

        $lines = [];

        foreach ($address as $field => $value) {
            if (! is_scalar($value) || (string) $value === '') {
                continue;
            }

            $lines[] = self::label((string) $field).': '.$value;
        }

        return $lines;
    }

    /**
     * `Str::headline()` alone answers `Line1` for the commonest address key there
     * is, because it splits on case and on underscores and a digit is neither.
     * Separating the digit first is the difference between `Line 1` and a label
     * somebody files a bug about.
     */
    private static function label(string $field): string
    {
        return Str::headline((string) preg_replace('/(\d+)/', ' $1', $field));
    }
}
