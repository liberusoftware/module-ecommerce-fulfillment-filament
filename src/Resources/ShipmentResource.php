<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Fulfillment\Actions\TransitionShipment;
use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Exceptions\IllegalShipmentTransition;
use Liberu\Ecommerce\Fulfillment\Exceptions\ShipmentExceedsOutstanding;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ListShipments;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\RelationManagers\ContentsRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Support\Address;
use Liberu\Ecommerce\Fulfillment\Filament\Support\Amount;
use Liberu\Ecommerce\Fulfillment\Filament\Support\PanelActor;
use Liberu\Ecommerce\Fulfillment\Models\Shipment;
use UnitEnum;

/**
 * A parcel, and a surface whose only writes are the state machine's own moves.
 *
 * ## There is no status field and no quantity field, anywhere
 *
 * `status` is deliberately absent from `Shipment::$fillable`, and so are the
 * three state timestamps and every counter on a line of demand, because
 * `Actions\TransitionShipment` is the only door. A panel that offered a status
 * `Select` would be a second door — one that skips the transition table, moves no
 * counter and stamps no timestamp — and it would be a door opened by a form the
 * framework fills from a request array.
 *
 * So this resource has **no form, no create page and no edit page**. A move is an
 * *action*, named for what it does, and the whole package constructs exactly one
 * input: the cancellation reason, which is a `Select` over a fixed vocabulary.
 *
 * ## An action is offered only when the machine will accept it
 *
 * Every transition button asks the domain's own transition table before it
 * renders:
 *
 *     $shipment->status->canTransitionTo($to)
 *
 * The panel keeps no list of its own, which matters more than it looks. Thirteen
 * of the sixteen ordered pairs are illegal, including all four self-transitions,
 * and asking the machine excludes every one of them without this file knowing
 * which they are. A button that always throws is a worse surface than no button.
 *
 * A double click is handled the same way rather than by hope: the first press
 * moves the parcel, the edge stops existing, and the button is gone before the
 * second press has anything to hit. The window a hidden button cannot close — a
 * colleague on another screen, a queued job, a carrier webhook — is closed by
 * re-reading the record before the domain is called, so what the operator is
 * shown is the domain's own refusal rather than a guess.
 *
 * ## Recording a parcel is not offered, and that is the domain's rule
 *
 * `ShipmentPolicy::create()` is permanently false. A parcel is *recorded*, from
 * an input carrying an idempotency key the caller supplies, and that key is the
 * whole guarantee that one van leaving is one row. A button minting a fresh key
 * on every press writes a second parcel on a double click and reports the same
 * goods leaving twice — which puts an order's fulfilled count ahead of the world,
 * in the one direction that ships free stock.
 *
 * ## Over-shipping is refused, never clamped
 *
 * There is no quantity input in this package at all, so nothing here can trim
 * one. Where the domain refuses a move because the numbers do not permit it, the
 * refusal reaches the operator **verbatim, with the outstanding quantity in it** —
 * see {@see dispatchAction()}. A surface that caught that exception and shipped
 * what was available would turn a loud failure into a partial dispatch nobody is
 * told about.
 *
 * ## Every ability is stated, because silence here is permission
 *
 * The unanswered gate case is permissive, and Filament's authorization helper
 * returns *allow* when a **present** policy has no method for the ability asked
 * about — so a partial policy is the same hazard as no policy and harder to see,
 * because the file exists and looks like a control.
 *
 * `ShipmentPolicy` publishes `viewAny`, `view`, `create`, `update`, `delete`,
 * `restore`, `forceDelete`, `dispatch` and `cancel`. `deleteAny`,
 * `forceDeleteAny`, `restoreAny`, `replicate` and `reorder` have no answer at all
 * and would every one default open on a model holding a destination address and a
 * carrier's tracking number. They are refused below by name, alongside the ones
 * the policy already denies, so the set reads as one list rather than as two
 * halves nobody can hold in mind.
 */
class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|UnitEnum|null $navigationGroup = 'Commerce';

    protected static ?string $navigationLabel = 'Shipments';

    protected static ?string $modelLabel = 'shipment';

    /**
     * A parcel is bound by its public reference and never by its id.
     *
     * The same argument that gives an order its number: an incrementing id in a
     * URL is an enumeration of everybody else's parcels, and a URL is the part of
     * a page that gets pasted into a support ticket. The domain mints
     * `reference` from the CSPRNG for exactly this, and a resource that ignored
     * it would have made the column decorative.
     *
     * This property governs only the *inbound* half: it is read in
     * `resolveRecordRouteBinding()`, which turns whatever arrived in the URL back
     * into a parcel. It has no say in what a generated URL says. Route
     * *generation* asks the route for a binding field and, finding none, falls
     * back to the model's own route key — the id. So the view page declares its
     * parameter as `{record:reference}` in `getPages()`, which is the half that
     * keeps the id out of the address bar. Both are needed; either alone is a
     * half-measure that reads like a control.
     */
    protected static ?string $recordRouteKeyName = 'reference';

    /**
     * Why a parcel was called off, as a fixed vocabulary.
     *
     * **A select and not a text box, and that is a privacy decision.**
     * `TransitionShipment` hands this value to `ShipmentCancelled`, and the
     * domain's `DomainEventLogger` copies it straight into a
     * `fulfillment.shipment_cancelled` log line — the domain's own docblock asks a
     * surface for a select on precisely those grounds. A free-text field is where
     * somebody types *"rang the shopper on 07700 900000"*, and a log is the store
     * in an application with the loosest access control and the longest
     * retention. A closed vocabulary cannot carry a person in it.
     *
     * The stored value is the key — a slug — so a log line reads
     * `reason: packed-in-error` whatever the panel's language is set to.
     *
     * @var array<string, string>
     */
    public const CANCELLATION_REASONS = [
        'packed-in-error' => 'It was packed in error',
        'out-of-stock' => 'The goods are not actually there',
        'damaged-in-warehouse' => 'The goods were damaged before it left',
        'duplicate-parcel' => 'A duplicate of another parcel',
        'address-problem' => 'The destination cannot be delivered to',
        'customer-request' => 'The shopper asked for it',
    ];

    public static function isScopedToTenant(): bool
    {
        return false;
    }

    /**
     * A parcel is *recorded*, under an idempotency key its caller supplies, and a
     * button minting a fresh key per press writes a second parcel on a double
     * click. `ShipmentPolicy::create()` says the same; this restates it so the
     * refusal does not depend on which of the two is read first.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /** What went in the box is a fact about a box that has been packed. */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** The count reported outward as fulfilled was built from this row. */
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
     * The team's parcels, with the contents every column and every move reads.
     *
     * The eager load is not only for the quantity column: `TransitionShipment`
     * walks `$shipment->lines` to move the counters, so without it every button
     * press is a query per row.
     *
     * @return Builder<Shipment>
     */
    public static function getEloquentQuery(): Builder
    {
        return PanelActor::scope(parent::getEloquentQuery()->with('lines'));
    }

    /**
     * There is no form.
     *
     * Nothing on a parcel is editable through this package, and no counter
     * anywhere is. The only schema in the whole source tree is the cancellation
     * action's one `Select`.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Parcel')
                    // The only searchable column in this resource, and it is the
                    // reference support quotes down a telephone. A table search
                    // term is persisted into the query string, which is written
                    // into every access log between here and the operator — so
                    // the tracking number is neither searchable nor filterable
                    // nor in this list at all. See `docs/domain.md`.
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ShipmentStatus $state): string => self::statusLabel($state))
                    ->color(fn (ShipmentStatus $state): string => self::statusColour($state))
                    ->sortable(),
                // A number with no relation and no foreign key. The module that
                // owns orders is not a dependency of this package and there is
                // nothing here to link to.
                TextColumn::make('order_id')
                    ->label('Order')
                    ->sortable(),
                TextColumn::make('carrier')
                    ->label('Carrier')
                    ->placeholder('Not recorded'),
                TextColumn::make('service')
                    ->label('Service')
                    ->placeholder('Not recorded')
                    ->toggleable(),
                TextColumn::make('quantity')
                    ->label('Goods')
                    ->state(fn (Shipment $record): int => self::totalQuantity($record))
                    ->numeric(),
                TextColumn::make('dispatched_at')
                    ->label('Dispatched')
                    ->dateTime()
                    ->placeholder('Not yet')
                    ->sortable(),
                TextColumn::make('delivered_at')
                    ->label('Delivered')
                    ->dateTime()
                    ->placeholder('Not yet')
                    ->toggleable(),
                TextColumn::make('carriage')
                    ->label('Carriage')
                    ->state(fn (Shipment $record): ?string => Amount::of($record, $record->shipping_cost_minor))
                    ->placeholder('Not recorded')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('supplier_id')
                    ->label('Supplier')
                    ->placeholder('Ours')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
                Filter::make('in_transit')
                    ->label('In transit')
                    // The domain's own scope, called by name rather than
                    // rewritten. A second copy of "in transit" here would be a
                    // second answer waiting to disagree.
                    ->query(fn (Builder $query): Builder => $query->scopes('inTransit')),
                Filter::make('stuck')
                    ->label('In transit for over a week')
                    // `scopeInTransitSince` takes a bound moment deliberately:
                    // `where('dispatched_at', null)` compiles to `is null` and
                    // would list every parcel that has somehow never been
                    // stamped rather than the ones that are late.
                    ->query(fn (Builder $query): Builder => $query->scopes(['inTransitSince' => now()->subWeek()])),
            ])
            ->recordActions([
                ViewAction::make(),
                ...self::transitionActions(),
                self::cancelAction(),
            ])
            ->defaultSort('id', 'desc');
    }

    /**
     * One action per forward move a parcel can make under its own steam.
     *
     * `cancelled` is **not** in this list: calling a parcel off asks for a reason
     * and puts goods back on the shelf, so it has its own action and its own
     * ability. See {@see cancelAction()}.
     *
     * @return list<Action>
     */
    public static function transitionActions(): array
    {
        return [
            self::dispatchAction(),
            self::deliverAction(),
        ];
    }

    /**
     * Report that the goods have left.
     *
     * Its own ability rather than a synonym for `update`, because reporting goods
     * as gone and putting them back on the shelf are different-sized mistakes.
     * `ShipmentPolicy::dispatch()` answers ownership *and* asks the machine, so
     * the button is asked twice and refuses twice.
     *
     * **Both refusals are surfaced, and neither is absorbed.**
     * `ShipmentExceedsOutstanding` carries the outstanding quantity in its
     * message, and it is shown as written. A panel that caught it and dispatched
     * what was available would be clamping — turning a loud failure into a partial
     * dispatch nobody is told about, in the one place where a wrong number is a
     * physical loss.
     */
    public static function dispatchAction(): Action
    {
        return Action::make('dispatch')
            ->label('Record as dispatched')
            ->icon('heroicon-o-truck')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Record as dispatched')
            ->modalDescription('The goods in this parcel are reported as having left. That count is final: nothing in this module lowers a dispatched quantity, and once it has moved the parcel cannot be called off. If the parcel comes back it is a return; if it turns out never to have left, that is a data correction rather than a cancellation.')
            ->modalSubmitActionLabel('It has left')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::Dispatched) && Gate::allows('dispatch', $record))
            ->action(function (Shipment $record): void {
                self::move($record, ShipmentStatus::Dispatched);
            });
    }

    /**
     * Record the handover.
     *
     * **The one move the domain publishes no separate ability for**, and this
     * package does not invent one: `ShipmentPolicy` names `dispatch` and `cancel`
     * because those two move counters in opposite directions, and delivery moves
     * none at all. Everything the goods needed accounting for happened when they
     * left. So the gate asked here is the ownership answer the policy does
     * publish — `view` — and the machine decides the rest. Asking for an ability
     * with no method behind it would be asking a question nothing answers, and an
     * unanswered question is the shape of every leak in this fleet.
     *
     * It is published all the same because delivery is when the returns clock
     * starts and when a stuck parcel stops being stuck.
     */
    public static function deliverAction(): Action
    {
        return Action::make('deliver')
            ->label('Record as delivered')
            ->icon('heroicon-o-home')
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Record as delivered')
            ->modalDescription('The parcel reached its destination. No counter moves — everything was accounted for when the goods left — but this is the handover at which a return becomes possible, and it is what takes the parcel off the in-transit list.')
            ->modalSubmitActionLabel('It arrived')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::Delivered) && Gate::allows('view', $record))
            ->action(function (Shipment $record): void {
                self::move($record, ShipmentStatus::Delivered);
            });
    }

    /**
     * Call a parcel off, and put its goods back on the shelf.
     *
     * Additionally gated on the domain's own answer: `ShipmentPolicy::cancel()`
     * asks `Shipment::isCancellable()`, which is false the moment a parcel is
     * dispatched. A staff member holding the ability still cannot get round the
     * boundary, because what is on the other side of it is another module.
     */
    public static function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Call this parcel off')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading('Call this parcel off')
            ->modalDescription('The goods go back on the shelf and can be packed into a different parcel immediately. Nothing is deleted and nothing is refunded — the demand is still owed, it is just not in this box any more.')
            ->modalSubmitActionLabel('Call it off')
            ->visible(fn (Shipment $record): bool => $record->status->canTransitionTo(ShipmentStatus::Cancelled) && Gate::allows('cancel', $record))
            ->schema([
                Select::make('reason')
                    ->label('Why')
                    ->options(self::CANCELLATION_REASONS)
                    ->required()
                    ->helperText('A fixed list rather than a free-text box: this word is copied into the module\'s own log line, and a text box next to an event logger is where a shopper\'s telephone number ends up in a log.'),
            ])
            ->action(function (Shipment $record, array $data): void {
                self::move($record, ShipmentStatus::Cancelled, (string) $data['reason']);
            });
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('The parcel')
                ->columns(3)
                ->schema([
                    TextEntry::make('reference')
                        ->label('Parcel'),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge()
                        ->formatStateUsing(fn (ShipmentStatus $state): string => self::statusLabel($state))
                        ->color(fn (ShipmentStatus $state): string => self::statusColour($state)),
                    TextEntry::make('order_id')
                        ->label('Order'),
                    TextEntry::make('dispatched_at')
                        ->label('Dispatched')
                        ->dateTime()
                        ->placeholder('Not dispatched'),
                    TextEntry::make('delivered_at')
                        ->label('Delivered')
                        ->dateTime()
                        ->placeholder('Not delivered'),
                    TextEntry::make('cancelled_at')
                        ->label('Called off')
                        ->dateTime()
                        ->placeholder('Not called off'),
                ]),
            Section::make('What can happen next')
                ->description('A parcel moves through one action per legal edge, and there is no status field: `TransitionShipment` is the only door and it refuses anything the state machine does not name.')
                ->columns(2)
                ->schema([
                    TextEntry::make('next_moves')
                        ->label('Legal moves from here')
                        ->getStateUsing(fn (Shipment $record): array => self::nextMoves($record))
                        ->listWithLineBreaks(),
                    // A missing button is a puzzle, and this is the refusal
                    // people arrive asking about.
                    TextEntry::make('cancellation')
                        ->label('Calling it off')
                        ->getStateUsing(fn (Shipment $record): string => self::cancellationNote($record)),
                ]),
            Section::make('Where it is going')
                ->description('This parcel\'s own destination. It is a copy taken when the request was raised, and a parcel given its own applies it here and rewrites nothing else — an order does not get rerouted, a box does.')
                ->schema([
                    TextEntry::make('destination')
                        ->label('Destination')
                        ->getStateUsing(fn (Shipment $record): array => Address::lines($record->destination))
                        ->listWithLineBreaks()
                        ->placeholder('None recorded'),
                ]),
            Section::make('The journey')
                ->description('A carrier is a string and a service level is a string. This module has no opinion about either and holds no list of them.')
                ->columns(3)
                ->schema([
                    TextEntry::make('carrier')
                        ->label('Carrier')
                        ->placeholder('Not recorded'),
                    TextEntry::make('service')
                        ->label('Service level')
                        ->placeholder('Not recorded'),
                    // Shown here, on a page the policy guards, and nowhere else.
                    // It is not a column, not searchable, not filterable and
                    // never written to a log — see the class docblock and
                    // `docs/domain.md`.
                    TextEntry::make('tracking_number')
                        ->label('Tracking')
                        ->placeholder('Not recorded'),
                    TextEntry::make('supplier_id')
                        ->label('Supplier')
                        ->placeholder('Ours'),
                    TextEntry::make('supplier_reference')
                        ->label('Their reference')
                        ->placeholder('None'),
                ]),
            Section::make('Carriage')
                ->description('What this parcel cost to send, as an integer count of the currency\'s smallest unit. It is not a charge to the shopper — what they paid for delivery is a line on their order, priced before anybody decided what goes in which box.')
                ->columns(3)
                ->schema([
                    TextEntry::make('shipping_cost_minor')
                        ->label('Carriage')
                        ->getStateUsing(fn (Shipment $record): ?string => Amount::of($record, $record->shipping_cost_minor))
                        ->placeholder('Not recorded'),
                    TextEntry::make('insured_value_minor')
                        ->label('Insured for')
                        ->getStateUsing(fn (Shipment $record): ?string => Amount::of($record, $record->insured_value_minor))
                        ->placeholder('Not insured'),
                    TextEntry::make('currency')
                        ->label('Currency')
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
            ContentsRelationManager::class,
        ];
    }

    /**
     * Index and view.
     *
     * No create page, because a parcel is recorded under a key its caller
     * supplies and there is no such thing as one somebody typed into a form. No
     * edit page, because what went in the box is a fact about the box.
     *
     * The view parameter carries an explicit binding field, `{record:reference}`.
     * That is what makes a generated URL say `SHP-…` rather than `1`; see
     * `$recordRouteKeyName` above for why the two halves are separate.
     *
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListShipments::route('/'),
            'view' => ViewShipment::route('/{record:reference}'),
        ];
    }

    /** Total goods in a parcel, across every line it carries. */
    public static function totalQuantity(Shipment $shipment): int
    {
        return (int) $shipment->lines->sum('quantity');
    }

    /**
     * The moves the machine will accept from where this parcel is, in words.
     *
     * Read off `allowedTransitions()` rather than written out, so it cannot
     * disagree with the buttons or with the domain.
     *
     * @return list<string>
     */
    public static function nextMoves(Shipment $shipment): array
    {
        $moves = array_map(
            fn (ShipmentStatus $status): string => self::statusLabel($status),
            $shipment->status->allowedTransitions(),
        );

        return $moves === []
            ? ['None. This parcel is '.strtolower(self::statusLabel($shipment->status)).', which is final.']
            : $moves;
    }

    /**
     * Why calling a parcel off is or is not on offer, in the domain's own words.
     *
     * **There is no void, and the sentence saying so belongs on the page rather
     * than only in the documentation.** A dispatched parcel is in the world:
     * somebody has it, and the quantity has already been reported outward as
     * fulfilled. The two real situations both have owners and neither is here — if
     * it comes back that is a return, and if it never actually left then it was
     * never dispatched and what happened is a mis-recorded fact, corrected by
     * whoever made the mistake. A third answer that quietly meant either would be
     * a status whose meaning depends on which one it was, and that is not a
     * status.
     */
    public static function cancellationNote(Shipment $shipment): string
    {
        return match ($shipment->status) {
            ShipmentStatus::Pending => 'Available. Nothing in this parcel has left, so calling it off puts the goods back on the shelf and they can be packed into a different parcel immediately.',
            ShipmentStatus::Cancelled => 'Already called off. The goods went back on the shelf at the time and are owed on the order still.',
            ShipmentStatus::Dispatched, ShipmentStatus::Delivered => 'Not available, and there is no void either. These goods have left: somebody has them, and the quantity has already been reported as fulfilled. If the parcel comes back, that is a return and it belongs to another module. If it turns out never to have left, then it was never dispatched — that is a mis-recorded fact and a data correction, which the runbook covers, rather than anything this panel does.',
        };
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (ShipmentStatus::cases() as $status) {
            $options[$status->value] = self::statusLabel($status);
        }

        return $options;
    }

    /**
     * Words rather than the stored slug.
     *
     * Four states and no more. There is deliberately no fifth for "came back",
     * "lost" or "voided": each of those is a fact another module owns, and a panel
     * that invented a label for one would be the place somebody started expecting
     * this module to write it.
     */
    public static function statusLabel(ShipmentStatus $status): string
    {
        return match ($status) {
            ShipmentStatus::Pending => 'Packed, not gone',
            ShipmentStatus::Dispatched => 'Dispatched',
            ShipmentStatus::Delivered => 'Delivered',
            ShipmentStatus::Cancelled => 'Called off',
        };
    }

    /**
     * Move the parcel, and say what happened.
     *
     * The record is re-read first. Between this page rendering and the button
     * being pressed the parcel may have moved — a second click, a colleague, a
     * queued job, a carrier webhook — and the domain must be asked about what is
     * in the database rather than about what this page was drawn from.
     *
     * Two refusals are caught and neither is softened. `IllegalShipmentTransition`
     * is the machine saying the edge does not exist, and its message names the
     * cancel-a-dispatched-parcel case explicitly. `ShipmentExceedsOutstanding` is
     * the arithmetic saying the counters do not permit it, and its message carries
     * the outstanding quantity — which is the number the operator needs and the
     * number a clamping surface would have silently used.
     */
    private static function move(Shipment $shipment, ShipmentStatus $to, ?string $reason = null): void
    {
        $shipment->refresh()->load('lines');

        try {
            app(TransitionShipment::class)->handle($shipment, $to, $reason);
        } catch (IllegalShipmentTransition|ShipmentExceedsOutstanding $exception) {
            Notification::make()
                ->title('Not moved')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(self::successTitle($to))
            ->body(self::successBody($to))
            ->success()
            ->send();
    }

    private static function statusColour(ShipmentStatus $status): string
    {
        return match ($status) {
            ShipmentStatus::Pending => 'warning',
            ShipmentStatus::Dispatched => 'info',
            ShipmentStatus::Delivered => 'success',
            ShipmentStatus::Cancelled => 'gray',
        };
    }

    private static function successTitle(ShipmentStatus $to): string
    {
        return match ($to) {
            ShipmentStatus::Dispatched => 'Recorded as dispatched',
            ShipmentStatus::Delivered => 'Recorded as delivered',
            ShipmentStatus::Cancelled => 'Parcel called off',
            ShipmentStatus::Pending => 'Recorded',
        };
    }

    private static function successBody(ShipmentStatus $to): string
    {
        return match ($to) {
            ShipmentStatus::Dispatched => 'The goods are reported as gone and the dispatched count has risen. That count never falls.',
            ShipmentStatus::Delivered => 'The handover is recorded. No counter moved, because everything was accounted for when the goods left.',
            ShipmentStatus::Cancelled => 'The goods are back on the shelf and are still owed on the order. Nothing was deleted.',
            ShipmentStatus::Pending => 'Recorded.',
        };
    }
}
