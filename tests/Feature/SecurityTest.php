<?php

use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Gate;
use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages\ListFulfillmentRequests;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages\ViewFulfillmentRequest;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\RelationManagers\ShipmentsRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ListShipments;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\RelationManagers\ContentsRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Support\PanelActor;
use Liberu\Ecommerce\Fulfillment\Models\FulfillmentRequest;
use Liberu\Ecommerce\Fulfillment\Models\Shipment;
use Liberu\PackageTestbench\TestUser;
use Livewire\Livewire;

/**
 * The three relation managers, with the kind of record each hangs off and the
 * page it hangs off it on.
 *
 * A plain constant walked in a `foreach` rather than a Pest dataset: a dataset is
 * built **before the application boots**, and anything in one that touches
 * Eloquent answers with a null connection. Folding the sweep into one test keeps
 * the model creation inside a booted application.
 */
const MANAGERS = [
    [LinesRelationManager::class, 'request', ViewFulfillmentRequest::class],
    [ShipmentsRelationManager::class, 'request', ViewFulfillmentRequest::class],
    [ContentsRelationManager::class, 'parcel', ViewShipment::class],
];

it('answers no team rather than every team when nobody is signed in', function () {
    expect(PanelActor::teamId())->toBeNull()
        ->and(ShipmentResource::getEloquentQuery()->count())->toBe(0)
        ->and(FulfillmentRequestResource::getEloquentQuery()->count())->toBe(0);
});

it('never lists the orphan rows a null team id would match', function () {
    $this->actingAs(TestUser::factory()->create());

    aParcel(aRequest(orderId: 9_000_511, teamId: null));
    aParcel(aRequest(orderId: 9_000_512, teamId: null));

    // `where('team_id', null)` compiles to `is null`, so an actor with no team
    // would be handed precisely the rows both policies deny everybody. The guard
    // is an explicit `whereRaw('1 = 0')`.
    expect(ShipmentResource::getEloquentQuery()->count())->toBe(0)
        ->and(FulfillmentRequestResource::getEloquentQuery()->count())->toBe(0)
        ->and(Shipment::query()->count())->toBe(2)
        ->and(FulfillmentRequest::query()->count())->toBe(2);
});

it('refuses another team\'s parcel and an unowned one to the team that can see neither', function () {
    $this->actorForTeam(TEAM);

    $orphan = aParcel(aRequest(orderId: 9_000_521, teamId: null));
    $theirs = aParcel(aRequest(orderId: 9_000_522, teamId: OTHER_TEAM));

    expect(ShipmentResource::getEloquentQuery()->count())->toBe(0)
        ->and(Gate::allows('view', $theirs))->toBeFalse()
        ->and(Gate::allows('view', $orphan))->toBeFalse()
        ->and(Gate::allows('dispatch', $theirs))->toBeFalse()
        ->and(Gate::allows('cancel', $theirs))->toBeFalse();

    Livewire::test(ListShipments::class)
        ->assertCanNotSeeTableRecords([$orphan, $theirs]);

    Livewire::test(ListFulfillmentRequests::class)
        ->assertCanNotSeeTableRecords([$orphan->request, $theirs->request]);
});

/**
 * The policies' own answers first, so every refusal below reads as deliberately
 * stricter than the domain rather than as dead code standing in for it.
 */
it('says yes to what the two policies publish before it overrides anything', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest());

    expect(Gate::allows('viewAny', Shipment::class))->toBeTrue()
        ->and(Gate::allows('view', $parcel))->toBeTrue()
        ->and(Gate::allows('dispatch', $parcel))->toBeTrue()
        ->and(Gate::allows('cancel', $parcel))->toBeTrue()
        ->and(Gate::allows('viewAny', FulfillmentRequest::class))->toBeTrue()
        ->and(Gate::allows('view', $parcel->request))->toBeTrue();
});

/**
 * `ShipmentPolicy` publishes `viewAny`, `view`, `create`, `update`, `delete`,
 * `restore`, `forceDelete`, `dispatch` and `cancel`, and
 * `FulfillmentRequestPolicy` publishes the first seven of those. `deleteAny`,
 * `forceDeleteAny`, `restoreAny`, `replicate` and `reorder` have no answer at
 * all on either — and Filament's authorization helper returns *allow* when a
 * present policy has no method for the ability asked about, so every one of them
 * would default open on models holding somebody's home address and a carrier's
 * tracking number.
 */
it('refuses by name every ability neither policy publishes', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest());
    $request = $parcel->request;

    expect(ShipmentResource::canDeleteAny())->toBeFalse()
        ->and(ShipmentResource::canForceDeleteAny())->toBeFalse()
        ->and(ShipmentResource::canRestoreAny())->toBeFalse()
        ->and(ShipmentResource::canReplicate($parcel))->toBeFalse()
        ->and(ShipmentResource::canReorder())->toBeFalse()
        // These the policies do answer, and answer false. Restated so the
        // refusal does not depend on which file is read first.
        ->and(ShipmentResource::canCreate())->toBeFalse()
        ->and(ShipmentResource::canEdit($parcel))->toBeFalse()
        ->and(ShipmentResource::canDelete($parcel))->toBeFalse()
        ->and(ShipmentResource::canForceDelete($parcel))->toBeFalse()
        ->and(ShipmentResource::canRestore($parcel))->toBeFalse()
        ->and(FulfillmentRequestResource::canDeleteAny())->toBeFalse()
        ->and(FulfillmentRequestResource::canForceDeleteAny())->toBeFalse()
        ->and(FulfillmentRequestResource::canRestoreAny())->toBeFalse()
        ->and(FulfillmentRequestResource::canReplicate($request))->toBeFalse()
        ->and(FulfillmentRequestResource::canReorder())->toBeFalse()
        ->and(FulfillmentRequestResource::canCreate())->toBeFalse()
        ->and(FulfillmentRequestResource::canEdit($request))->toBeFalse()
        ->and(FulfillmentRequestResource::canDelete($request))->toBeFalse()
        ->and(FulfillmentRequestResource::canForceDelete($request))->toBeFalse()
        ->and(FulfillmentRequestResource::canRestore($request))->toBeFalse();
});

it('refuses the domain\'s own writes to the team that owns the goods', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest());

    expect(Gate::allows('create', Shipment::class))->toBeFalse()
        ->and(Gate::allows('update', $parcel))->toBeFalse()
        ->and(Gate::allows('delete', $parcel))->toBeFalse()
        ->and(Gate::allows('create', FulfillmentRequest::class))->toBeFalse()
        ->and(Gate::allows('update', $parcel->request))->toBeFalse()
        ->and(Gate::allows('delete', $parcel->request))->toBeFalse();
});

it('asks for no ability the domain does not publish', function () {
    $this->actorForTeam(TEAM);

    $parcel = parcelAt(ShipmentStatus::Dispatched);

    // `deliver` is not on `ShipmentPolicy` and this package does not pretend it
    // is: recording a handover moves no counter, so the domain names no separate
    // ability for it and the deliver action gates on ownership — `view` — plus
    // the transition table. Asking for an ability with no method behind it is
    // asking a question nothing answers, and an unanswered question is the shape
    // of every leak in this fleet.
    expect(Gate::allows('deliver', $parcel))->toBeFalse()
        ->and(Gate::allows('view', $parcel))->toBeTrue();

    Livewire::test(ListShipments::class)
        ->assertActionVisible(TestAction::make('deliver')->table($parcel));
});

it('offers no page anybody could edit a parcel, a request or a counter from', function () {
    // A create page or an edit page appearing here would be the first sign that
    // somebody scaffolded their way past every refusal in either resource — and
    // an edit page is where a status field and an editable counter come from.
    expect(array_keys(ShipmentResource::getPages()))->toBe(['index', 'view'])
        ->and(array_keys(FulfillmentRequestResource::getPages()))->toBe(['index', 'view']);
});

/**
 * None of the three tables under a relation manager here is one a policy answers
 * for by the right type, and two of them have no policy at all. Every `can…`
 * below would answer `true` by default, which is the leak this fleet has shipped
 * three times.
 */
it('forces every write off every relation manager', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest());

    foreach (MANAGERS as [$manager, $kind, $page]) {
        $owner = $kind === 'parcel' ? $parcel : $parcel->request;

        $component = Livewire::test($manager, [
            'ownerRecord' => $owner,
            'pageClass' => $page,
        ])->instance();

        $can = fn (string $method, mixed ...$arguments): bool => (bool) (new ReflectionMethod($component, $method))
            ->invoke($component, ...$arguments);

        expect($component->isReadOnly())->toBeTrue($manager)
            ->and($can('canViewAny'))->toBeTrue($manager)
            ->and($can('canCreate'))->toBeFalse($manager)
            ->and($can('canEdit', $owner))->toBeFalse($manager)
            ->and($can('canDelete', $owner))->toBeFalse($manager)
            ->and($can('canDeleteAny'))->toBeFalse($manager)
            ->and($can('canForceDelete', $owner))->toBeFalse($manager)
            ->and($can('canForceDeleteAny'))->toBeFalse($manager)
            ->and($can('canRestore', $owner))->toBeFalse($manager)
            ->and($can('canRestoreAny'))->toBeFalse($manager)
            ->and($can('canReplicate', $owner))->toBeFalse($manager)
            ->and($can('canReorder'))->toBeFalse($manager)
            // The two that are live on a `hasMany` and default open. An
            // associate here is how one merchant's line ends up filed against
            // another merchant's parcel, and the counters would then be moved
            // against the wrong demand.
            ->and($can('canAssociate'))->toBeFalse($manager)
            ->and($can('canDissociate', $owner))->toBeFalse($manager)
            ->and($can('canDissociateAny'))->toBeFalse($manager)
            ->and($can('canAttach'))->toBeFalse($manager)
            ->and($can('canDetach', $owner))->toBeFalse($manager)
            ->and($can('canDetachAny'))->toBeFalse($manager);
    }
});

it('keeps every relation manager away from another team\'s goods and from nobody\'s', function () {
    $this->actorForTeam(TEAM);

    $theirs = aParcel(aRequest(orderId: 9_000_531, teamId: OTHER_TEAM));
    $orphan = aParcel(aRequest(orderId: 9_000_532, teamId: null));

    foreach (MANAGERS as [$manager, $kind, $page]) {
        foreach ([$theirs, $orphan] as $parcel) {
            $owner = $kind === 'parcel' ? $parcel : $parcel->request;

            expect($manager::canViewForRecord($owner, $page))->toBeFalse($manager);
        }
    }
});

it('puts no tracking number and no destination into the query string', function () {
    $this->actorForTeam(TEAM);

    aParcel(aRequest(), trackingNumber: 'TRK-SECRET-9001');

    $searchable = fn (array $columns): array => array_values(array_map(
        fn ($column): string => $column->getName(),
        array_filter($columns, fn ($column): bool => $column->isSearchable()),
    ));

    $shipments = Livewire::test(ListShipments::class)->instance()->getTable();
    $requests = Livewire::test(ListFulfillmentRequests::class)->instance()->getTable();

    // A search term and a filter's state are both persisted into the URL, and a
    // query string is written into every access log on the path. So the only
    // searchable columns are the two public references a customer quotes down a
    // telephone, and the tracking number is not a column at all.
    expect($searchable($shipments->getColumns()))->toBe(['reference'])
        ->and(array_keys($shipments->getFilters()))->toBe(['status', 'in_transit', 'stuck'])
        ->and($searchable($requests->getColumns()))->toBe(['order_number'])
        ->and(array_keys($requests->getFilters()))->toBe(['outstanding'])
        ->and(array_keys($shipments->getColumns()))->not->toContain('tracking_number')
        ->and(array_keys($shipments->getColumns()))->not->toContain('destination');
});

it('never lets a tracking number reach a log line', function () {
    $this->actorForTeam(TEAM);

    $records = captureLog();

    $parcel = aParcel(aRequest(), trackingNumber: 'TRK-SECRET-9001');

    Livewire::test(ListShipments::class)
        ->callAction(TestAction::make('dispatch')->table($parcel));

    $written = json_encode($records(), JSON_THROW_ON_ERROR);

    expect($written)->not->toContain('TRK-SECRET-9001')
        ->and($written)->not->toContain('1 High Street')
        // Proof the capture works at all, so the two refusals above are not
        // passing against an empty array.
        ->and($written)->toContain('fulfillment.shipment_dispatched');
});

it('shows the tracking number only on the page the policy guards', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest(), trackingNumber: 'TRK-SECRET-9001');

    // Evidence exists to be produced: somebody already authorized for this
    // parcel is exactly who should be able to read it. The rule is about where it
    // may travel, not about who may read it on a guarded page.
    Livewire::test(ViewShipment::class, ['record' => $parcel->reference])
        ->assertOk()
        ->assertSee('TRK-SECRET-9001');

    Livewire::test(ListShipments::class)
        ->assertOk()
        ->assertDontSee('TRK-SECRET-9001');
});

it('puts the parcel\'s public reference in the URL rather than its id', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest());

    // An incrementing id in a URL is an enumeration of everybody else's parcels,
    // and a URL is the part of a page that gets pasted into a support ticket.
    expect(ShipmentResource::getRecordRouteKeyName())->toBe('reference')
        ->and($parcel->reference)->toStartWith('SHP-')
        ->and(ShipmentResource::getUrl('view', ['record' => $parcel]))->toContain($parcel->reference);
});
