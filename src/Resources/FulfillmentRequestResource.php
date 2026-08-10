<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources;

use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages\ListFulfillmentRequests;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages\ViewFulfillmentRequest;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\RelationManagers\ShipmentsRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Support\Accounting;
use Liberu\Ecommerce\Fulfillment\Filament\Support\Address;
use Liberu\Ecommerce\Fulfillment\Filament\Support\PanelActor;
use Liberu\Ecommerce\Fulfillment\Models\FulfillmentRequest;
use Liberu\Ecommerce\Fulfillment\Queries\FulfillmentQuery;
use UnitEnum;

/**
 * What an order still owes this module: the pick list, and nothing writable at
 * all.
 *
 * ## This resource has no action, and that is the whole design
 *
 * `FulfillmentRequestPolicy` answers **false** to `create`, `update`, `delete`,
 * `restore` and `forceDelete`, each for a reason rather than out of caution. A
 * request comes from an order through an action with an idempotency key, so there
 * is no such thing as one somebody typed in. The quantities are what the order
 * says, so editing them here would make this module's copy disagree with the
 * record it was copied from — and the copy is the one the warehouse works from.
 * The lines are what every parcel points at, so deleting the row would leave the
 * parcels pointing at nothing.
 *
 * What is genuinely missing from the policy is an ability to **re-address** a
 * request, and it is missing on purpose. A reroute belongs to one parcel, not to
 * an order: an order does not get rerouted, a box does. So the destination shown
 * here is a default, and changing where goods actually go is done on the parcel.
 *
 * ## The counters are shown and never typed
 *
 * Three of them — committed, dispatched, cancelled — plus the two counts the
 * domain derives from them. They are read-only everywhere in this package because
 * the arithmetic that keeps them honest lives with them: `committed + cancelled ≤
 * quantity` and `dispatched ≤ committed`, both refused rather than clamped.
 * **`committed` may fall and `dispatched` may not**, which is why they are two
 * columns rather than one, and there is no field, action or bulk operation
 * anywhere in this package that could lower a dispatched count.
 *
 * ## Every ability is stated, because silence here is permission
 *
 * `FulfillmentRequestPolicy` publishes `viewAny`, `view`, `create`, `update`,
 * `delete`, `restore` and `forceDelete`. `deleteAny`, `forceDeleteAny`,
 * `restoreAny`, `replicate` and `reorder` have no answer at all, and Filament's
 * authorization helper returns *allow* when a present policy has no method for
 * the ability asked about — so every one of them would default open on a model
 * holding somebody's home address. They are refused below by name.
 */
class FulfillmentRequestResource extends Resource
{
    protected static ?string $model = FulfillmentRequest::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'To fulfil';

    protected static ?string $modelLabel = 'fulfillment request';

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    /** A request comes from an order, through an action with an idempotency key. */
    public static function canCreate(): bool
    {
        return false;
    }

    /** The quantities are what the order says. This is a copy, not a source. */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** The lines are what every parcel points at. */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    public static function canRestore(Model $record): bool
    {
        return false;
    }

    public static function canRestoreAny(): bool
    {
        return false;
    }

    public static function canReplicate(Model $record): bool
    {
        return false;
    }

    public static function canReorder(): bool
    {
        return false;
    }

    /**
     * The team's requests, with the lines every count on the page is summed from.
     *
     * @return Builder<FulfillmentRequest>
     */
    public static function getEloquentQuery(): Builder
    {
        return PanelActor::scope(parent::getEloquentQuery()->with('lines'));
    }

    /** There is no form. Nothing here is editable through this package. */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('order_number')
                    ->label('Order')
                    // The only searchable column, and it is the reference the
                    // customer quotes down a telephone. A search term is
                    // persisted into the query string, which is written into
                    // every access log on the path — so the destination is on the
                    // page and in neither the search nor the filters.
                    ->searchable()
                    ->sortable()
                    ->placeholder('No number given'),
                TextColumn::make('order_id')
                    ->label('Order id')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('lines_count')
                    ->label('Lines')
                    ->counts('lines'),
                TextColumn::make('outstanding')
                    ->label('Still to pick')
                    // The domain's own arithmetic, summed. Nothing here
                    // subtracts: the point of the domain publishing
                    // `remainingQuantity()` is that no consumer derives it and
                    // gets it wrong.
                    ->state(fn (FulfillmentRequest $record): int => Accounting::remainingOf($record))
                    ->numeric(),
                // Where the goods have got to, in words. There is no status on a
                // line, because a line of five can be two dispatched, one packed,
                // one cancelled and one still to pick at the same instant.
                TextColumn::make('accounting')
                    ->label('Accounted for')
                    ->state(fn (FulfillmentRequest $record): string => Accounting::ofRequest($record)),
                TextColumn::make('shipments_count')
                    ->label('Parcels')
                    ->counts('shipments'),
                TextColumn::make('created_at')
                    ->label('Requested')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('store_id')
                    ->label('Store')
                    ->placeholder('None')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('outstanding')
                    ->label('Still has goods to pack')
                    // The domain's own query, asked rather than reimplemented.
                    // `FulfillmentQuery::outstanding()` compares the columns —
                    // `quantity > committed + cancelled` — because a stored
                    // "remaining" would be a fourth number to keep in step with
                    // three others, and the first time it drifted nobody would
                    // know which was right.
                    ->query(fn (Builder $query): Builder => $query->whereIn(
                        $query->getModel()->getQualifiedKeyName(),
                        app(FulfillmentQuery::class)->outstanding()->select('id'),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The order')
                ->description('An order id and an order number, held as numbers and never resolved. The module that owns orders is not a dependency of this package, and a request has to keep meaning something with that module absent.')
                ->columns(3)
                ->schema([
                    TextEntry::make('order_number')
                        ->label('Order')
                        ->placeholder('No number given'),
                    TextEntry::make('order_id')
                        ->label('Order id'),
                    TextEntry::make('store_id')
                        ->label('Store')
                        ->placeholder('None'),
                    TextEntry::make('created_at')
                        ->label('Requested')
                        ->dateTime(),
                    TextEntry::make('accounting')
                        ->label('Accounted for')
                        ->getStateUsing(fn (FulfillmentRequest $record): string => Accounting::ofRequest($record)),
                    TextEntry::make('completeness')
                        ->label('Anything left')
                        ->getStateUsing(fn (FulfillmentRequest $record): string => self::completenessNote($record)),
                ]),
            Section::make('Where the goods are going by default')
                ->description('This module\'s own copy of the destination, taken when the request was raised. A parcel inherits it and may be given its own instead — rerouting one box changes where that box goes and rewrites nothing else. There is deliberately no way to re-address a request.')
                ->schema([
                    TextEntry::make('destination')
                        ->label('Destination')
                        ->getStateUsing(fn (FulfillmentRequest $record): array => Address::lines($record->destination))
                        ->listWithLineBreaks()
                        ->placeholder('None recorded'),
                ]),
        ]);
    }

    /**
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            LinesRelationManager::class,
            ShipmentsRelationManager::class,
        ];
    }

    /**
     * Index and view. There is no create page and no edit page, because there is
     * nothing on a request anybody may write.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListFulfillmentRequests::route('/'),
            'view' => ViewFulfillmentRequest::route('/{record}'),
        ];
    }

    /**
     * Whether anything is still owed, said as a sentence.
     *
     * `isComplete()` is the domain's own answer over its own lines. Complete here
     * means nothing is left to *commit* to a parcel — goods sitting in a packed
     * parcel that has not left are already committed, so a complete request may
     * still have parcels to dispatch.
     */
    public static function completenessNote(FulfillmentRequest $request): string
    {
        if (! $request->isComplete()) {
            return Accounting::remainingOf($request).' still to put in a box.';
        }

        return 'Nothing left to pack. Every unit is in a parcel, gone, or called off — though a packed parcel may still be waiting to leave.';
    }
}
