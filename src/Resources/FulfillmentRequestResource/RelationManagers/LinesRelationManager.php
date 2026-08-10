<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\WarehouseRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Support\Accounting;
use Liberu\Ecommerce\Fulfillment\Models\FulfillmentLine;

/**
 * One order line's worth of demand, and the four numbers that answer for it.
 *
 * ## Three counters, not a status
 *
 * There is deliberately no status column on a line, in the schema or here,
 * because a line of five can be two dispatched, one sitting in a packed parcel,
 * one cancelled and one still to pick **at the same instant**, and no single word
 * says that. So the counters are shown as counters and summarised as a sentence.
 *
 * The two derived counts are the domain's own methods rather than subtractions
 * written here:
 *
 *     still to pick     = quantity − committed − cancelled
 *     packed and waiting = committed − dispatched
 *
 * The point of the domain publishing them is that no consumer derives one and
 * gets it wrong, and this is a consumer.
 *
 * ## `committed` may fall. `dispatched` may not. That is why there are two
 *
 * `committed` is a **reservation**: calling off a parcel that never left puts its
 * goods back on the shelf and this number drops. `dispatched` is a **fact**: it is
 * the count reported outward as fulfilled, and a running total that can fall is
 * one nobody can audit. There is no move anywhere in the domain that lowers it,
 * and there is no field, action or bulk operation anywhere in this package that
 * could — which is the single most important thing about this table being
 * read-only.
 *
 * ## The order line id is shown, because other modules hold it
 *
 * It is stable and public, and a line is never deleted and never replaced. That
 * promise is the only reason this column can be a bare integer with no foreign
 * key, and it is what an operator is holding when they reconcile a parcel against
 * an order.
 *
 * `name` and `sku` are copies taken when the request was raised, so a packing
 * slip printed in November still names what was actually put in the box after the
 * catalogue has renamed, re-priced or deleted it. `product_id` and `variant_id`
 * are numbers with no relation and no foreign key, so they are shown as numbers.
 *
 * `ecommerce_fulfillment_lines` has **no policy at all**, which is why the base
 * class answers `isReadOnly()` unconditionally and refuses every ability by name.
 */
class LinesRelationManager extends WarehouseRelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'What is owed';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('order_line_id')
                    ->label('Order line')
                    ->prefix('#'),
                TextColumn::make('name')
                    ->label('Item')
                    ->wrap(),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->placeholder('None'),
                TextColumn::make('quantity')
                    ->label('Owed')
                    ->numeric(),
                TextColumn::make('accounting')
                    ->label('Accounted for')
                    // Words, not a bar. A progress bar has no accessible value
                    // unless somebody remembers to give it one, and this is the
                    // question the page is open to answer.
                    ->state(fn (FulfillmentLine $record): string => Accounting::ofLine($record)),
                TextColumn::make('remaining')
                    ->label('Still to pick')
                    ->state(fn (FulfillmentLine $record): int => $record->remainingQuantity())
                    ->numeric(),
                TextColumn::make('undispatched')
                    ->label('Packed and waiting')
                    ->state(fn (FulfillmentLine $record): int => $record->undispatchedQuantity())
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('committed_quantity')
                    ->label('Committed')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('dispatched_quantity')
                    ->label('Dispatched')
                    ->numeric(),
                TextColumn::make('cancelled_quantity')
                    ->label('Cancelled')
                    ->numeric()
                    ->toggleable(),
                TextColumn::make('product_id')
                    ->label('Product')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('variant_id')
                    ->label('Variant')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('position');
    }
}
