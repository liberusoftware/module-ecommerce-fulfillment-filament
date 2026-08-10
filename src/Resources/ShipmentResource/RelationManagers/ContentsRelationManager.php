<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\WarehouseRelationManager;

/**
 * What is actually in the box.
 *
 * One row per order line that has some of its goods in this parcel — the join
 * that four columns on an order could never be. A line of five can split across
 * three parcels and three lines can share one, and both directions have to be
 * sayable at once.
 *
 * ## The order line id is shown, because that is the whole contract
 *
 * An order line id and a quantity is the entire payload the dispatch event
 * carries, and it is what the host reads to raise the fulfilled counter on the
 * far side. Two integers, no classes. An operator reconciling a parcel against an
 * order is holding that number, and a surface that made them count rows to find
 * it would be asking them to guess.
 *
 * A line id is safe to hold because the module that owns orders promises it:
 * a line is never deleted and never replaced, so this number names the same line
 * of the same order for as long as that order exists.
 *
 * ## Nothing here is editable, and the quantity least of all
 *
 * This number is what moved `committed_quantity` when the parcel was recorded and
 * what will move `dispatched_quantity` when it leaves. Editing it would change
 * how much is reported as fulfilled without changing what is in the box — and
 * the counters would then disagree with the warehouse in the direction that ships
 * free stock. The base class refuses every write by name and answers
 * `isReadOnly()` unconditionally, which Filament consults before any policy.
 *
 * `ecommerce_fulfillment_shipment_lines` has **no policy at all**, which is
 * exactly why: a model with no policy is exposed, not safe.
 */
class ContentsRelationManager extends WarehouseRelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'What is in the box';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_line_id')
                    ->label('Order line')
                    ->prefix('#'),
                TextColumn::make('quantity')
                    ->label('In this parcel')
                    ->numeric(),
                TextColumn::make('line.name')
                    ->label('Item')
                    ->wrap(),
                TextColumn::make('line.sku')
                    ->label('SKU')
                    ->placeholder('None'),
                TextColumn::make('line.quantity')
                    ->label('Ordered')
                    ->numeric()
                    ->toggleable(),
            ])
            ->defaultSort('id');
    }
}
