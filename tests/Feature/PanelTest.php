<?php

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Filament\FulfillmentPlugin;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages\ListFulfillmentRequests;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages\ViewFulfillmentRequest;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\RelationManagers\ShipmentsRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ListShipments;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\RelationManagers\ContentsRelationManager;
use Liberu\Ecommerce\Fulfillment\Models\Shipment;
use Livewire\Livewire;

it('contributes exactly the two resources that have a policy behind them', function () {
    $plugin = FulfillmentPlugin::make();

    expect($plugin->getId())->toBe('liberu-ecommerce-fulfillment');

    // The lines of demand and the rows saying how much went in which box are
    // relation managers, never resources: neither table carries a `team_id`, so
    // any top-level list of either is a cross-tenant list by construction.
    expect(array_values(Filament::getPanel('admin')->getResources()))
        ->toHaveCount(2)
        ->toContain(FulfillmentRequestResource::class)
        ->toContain(ShipmentResource::class);
});

it('lists an order\'s demand and the parcels raised against it', function () {
    $this->actorForTeam(TEAM);

    $request = aRequest(quantity: 5);
    $parcel = aParcel($request, quantity: 2);

    Livewire::test(ListFulfillmentRequests::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$request])
        ->assertTableColumnStateSet('outstanding', 3, $request->fresh()->load('lines'))
        ->assertSee('ORD-GHOST'.GHOST_ORDER);

    Livewire::test(ListShipments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$parcel])
        ->assertTableColumnStateSet('quantity', 2, $parcel);

    expect(ShipmentResource::totalQuantity($parcel))->toBe(2);
});

it('walks from an order\'s demand to a parcel and back to the order line inside it', function () {
    $this->actorForTeam(TEAM);

    $request = aRequest(quantity: 5);
    $parcel = aParcel($request, quantity: 2);
    $content = $parcel->lines->firstOrFail();

    // Held onto rather than indexed out of the relation: a `hasMany` collection
    // carries no ordering of its own.
    Livewire::test(ShipmentsRelationManager::class, [
        'ownerRecord' => $request->refresh(),
        'pageClass' => ViewFulfillmentRequest::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$parcel])
        ->assertTableColumnStateSet('quantity', 2, $parcel);

    Livewire::test(ContentsRelationManager::class, [
        'ownerRecord' => $parcel,
        'pageClass' => ViewShipment::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$content])
        // An order line id and a quantity. That is the entire payload the
        // dispatch event carries and the whole contract the boundary rests on.
        ->assertTableColumnStateSet('order_line_id', ghostLine(), $content)
        ->assertTableColumnStateSet('quantity', 2, $content);

    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $request->refresh(),
        'pageClass' => ViewFulfillmentRequest::class,
    ])
        ->assertOk()
        ->assertSee('Merino Crew')
        ->assertSee('GHOST-1');
});

it('renders both view pages without asking for a relation no deployment has configured', function () {
    $this->actorForTeam(TEAM);

    $request = aRequest();
    $parcel = aParcel($request);

    Livewire::test(ViewFulfillmentRequest::class, ['record' => $request->getRouteKey()])
        ->assertOk()
        ->assertSee('ORD-GHOST'.GHOST_ORDER)
        ->assertSee('Line 1: 1 High Street');

    Livewire::test(ViewShipment::class, ['record' => $parcel->reference])
        ->assertOk()
        ->assertSee($parcel->reference)
        // A carrier is a string this module has no opinion about, and a service
        // level is another.
        ->assertSee('haulier-one')
        ->assertSee('next-day')
        // Integer minor units, rendered by string arithmetic.
        ->assertSee('GBP 5.99');
});

it('shows only orders that still have goods to pack when asked', function () {
    $this->actorForTeam(TEAM);

    $open = aRequest(orderId: 9_000_561, quantity: 5);
    $done = aRequest(orderId: 9_000_562, quantity: 2);

    // Everything the second order owes is now in a parcel, so nothing is left to
    // commit. The domain compares the columns rather than storing a fourth
    // number, and this filter asks the domain rather than repeating it.
    aParcel($done, quantity: 2, key: 'parcel-done');

    Livewire::test(ListFulfillmentRequests::class)
        ->assertCanSeeTableRecords([$open, $done])
        ->filterTable('outstanding')
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$done]);
});

it('says whether anything is left to pack, and does not confuse packed with gone', function () {
    $this->actorForTeam(TEAM);

    $request = aRequest(quantity: 2);

    expect(FulfillmentRequestResource::completenessNote($request))
        ->toBe('2 still to put in a box.');

    aParcel($request, quantity: 2);

    // Complete means nothing is left to *commit*. The goods are in a parcel that
    // has not left, which is a different fact from having gone — and the sentence
    // says so, because "complete" next to an undispatched parcel is exactly the
    // thing somebody misreads.
    expect(FulfillmentRequestResource::completenessNote($request->fresh()->load('lines')))
        ->toContain('Nothing left to pack')
        ->toContain('may still be waiting to leave');
});

it('filters parcels by the domain\'s own scopes rather than by a second copy of them', function () {
    $this->actorForTeam(TEAM);

    $pending = parcelAt(ShipmentStatus::Pending, orderId: 9_000_571);
    $flying = parcelAt(ShipmentStatus::Dispatched, orderId: 9_000_572);

    // Dispatched a fortnight ago and still not delivered — the stuck-parcel
    // sweep. Nothing in either package runs on a timer; the host's schedule
    // decides what to do with these.
    $stuck = parcelAt(ShipmentStatus::Dispatched, orderId: 9_000_573);
    $stuck->forceFill(['dispatched_at' => now()->subDays(14)])->save();

    Livewire::test(ListShipments::class)
        ->filterTable('status', ShipmentStatus::Pending->value)
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$flying, $stuck]);

    Livewire::test(ListShipments::class)
        ->filterTable('in_transit')
        ->assertCanSeeTableRecords([$flying, $stuck])
        ->assertCanNotSeeTableRecords([$pending]);

    Livewire::test(ListShipments::class)
        ->filterTable('stuck')
        ->assertCanSeeTableRecords([$stuck])
        ->assertCanNotSeeTableRecords([$pending, $flying]);
});

it('shows a supplier as an identifier and a reference, with no supplier anything', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest());
    // Dropshipping is a sourcing decision made before this one. What survives
    // into a parcel is "somebody else's warehouse sent this, and they call it X":
    // no supplier model, no supplier relation, no supplier logic.
    $parcel->forceFill(['supplier_id' => 'supplier-77', 'supplier_reference' => 'THEIR-REF-1'])->save();

    Livewire::test(ViewShipment::class, ['record' => $parcel->reference])
        ->assertOk()
        ->assertSee('supplier-77')
        ->assertSee('THEIR-REF-1');

    expect(Shipment::query()->where('supplier_id', 'supplier-77')->count())->toBe(1);
});

it('runs with no module that owns orders installed, over ids nothing has heard of', function () {
    $this->actorForTeam(TEAM);

    // The boundary this package inherits and does not weaken. The order id and
    // the order line id on every row here name nothing in this database, and no
    // table of orders exists to name.
    expect(Schema::hasTable('ecommerce_orders_orders'))->toBeFalse()
        ->and(Schema::hasTable('orders'))->toBeFalse();

    $parcel = aParcel(aRequest());

    Livewire::test(ListShipments::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$parcel]);

    expect($parcel->order_id)->toBe(GHOST_ORDER)
        ->and($parcel->lines->firstOrFail()->order_line_id)->toBe(ghostLine());
});
