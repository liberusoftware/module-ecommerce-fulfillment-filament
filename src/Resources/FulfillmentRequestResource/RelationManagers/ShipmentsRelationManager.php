<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\RelationManagers;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\WarehouseRelationManager;
use Liberu\Ecommerce\Fulfillment\Models\Shipment;

/**
 * Every parcel raised against this order, oldest first.
 *
 * **This is the cardinality the whole module exists for.** An order that ships in
 * three boxes on three carriers on three days has three carriers, and a column on
 * an order can only ever hold the first answer — the second parcel makes it wrong
 * and nothing in the schema notices. One row per parcel is what those columns
 * should have been.
 *
 * ## Read-only here, and moved from the parcel's own page
 *
 * `Shipment` does have a policy, unlike the two tables of lines — but a relation
 * manager's default authorization asks the gate about the **related** model,
 * which is not the model this page's owner record is. `isReadOnly()` is answered
 * unconditionally, which Filament consults before any policy, so the question
 * never reaches a gate that could answer it about the wrong thing.
 *
 * The state machine lives on {@see ShipmentResource}, where a move is one button
 * against one parcel that the machine has been asked about. Offering the same
 * moves from a list hanging off an order would invite a bulk action, and there is
 * no such thing as bulk-dispatching: each parcel is a separate fact about
 * separate goods, and a bulk failure part-way through is a set of parcels in
 * unknown states.
 */
class ShipmentsRelationManager extends WarehouseRelationManager
{
    protected static string $relationship = 'shipments';

    protected static ?string $title = 'Parcels';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reference')
            ->columns([
                TextColumn::make('reference')
                    ->label('Parcel'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    // Words as well as a colour: amber and grey are the same
                    // badge to a screen reader and to anybody who cannot
                    // separate the two.
                    ->formatStateUsing(fn (ShipmentStatus $state): string => ShipmentResource::statusLabel($state))
                    ->color(fn (ShipmentStatus $state): string => match ($state) {
                        ShipmentStatus::Pending => 'warning',
                        ShipmentStatus::Dispatched => 'info',
                        ShipmentStatus::Delivered => 'success',
                        ShipmentStatus::Cancelled => 'gray',
                    }),
                TextColumn::make('carrier')
                    ->label('Carrier')
                    ->placeholder('Not recorded'),
                TextColumn::make('service')
                    ->label('Service')
                    ->placeholder('Not recorded')
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Goods')
                    ->state(fn (Shipment $record): int => ShipmentResource::totalQuantity($record))
                    ->numeric(),
                TextColumn::make('dispatched_at')
                    ->label('Dispatched')
                    ->dateTime()
                    ->placeholder('Not yet'),
                TextColumn::make('delivered_at')
                    ->label('Delivered')
                    ->dateTime()
                    ->placeholder('Not yet')
                    ->toggleable(),
            ])
            ->defaultSort('id');
    }
}
