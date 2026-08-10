<?php

use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ListShipments;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use Liberu\Ecommerce\Fulfillment\Models\FulfillmentLine;
use Livewire\Livewire;

// This panel's whole job is the state machine and the counters it moves, so this
// is the file that says whether it did them. The rule under test throughout: **an
// action is offered only when the machine will accept it**, and a button that
// always throws is a worse surface than no button.

/** The three actions this package offers, and the state each one moves a parcel to. */
const MOVES = [
    'dispatch' => 'dispatched',
    'deliver' => 'delivered',
    'cancel' => 'cancelled',
];

it('offers a button for three of the sixteen ordered pairs and for none of the other thirteen', function () {
    $legal = [];

    foreach (ShipmentStatus::cases() as $from) {
        foreach (ShipmentStatus::cases() as $to) {
            if ($from->canTransitionTo($to)) {
                $legal[] = $from->value.' -> '.$to->value;
            }
        }
    }

    // The set the panel derives its buttons from, pinned here so that adding an
    // edge to the domain fails this repository too and somebody has to decide
    // whether it needs a button — rather than the edge quietly existing with no
    // way to use it. The absence of `dispatched -> cancelled` is the module's
    // sharpest rule and it is asserted here by the shape of this list.
    expect($legal)->toBe([
        'pending -> dispatched',
        'pending -> cancelled',
        'dispatched -> delivered',
    ]);
});

it('offers exactly the moves that are legal from where the parcel is', function (ShipmentStatus $status, array $offered) {
    $this->actorForTeam(TEAM);

    $parcel = parcelAt($status);

    $component = Livewire::test(ListShipments::class)->assertCanSeeTableRecords([$parcel]);

    foreach (MOVES as $action => $destination) {
        $expected = in_array($destination, $offered, true);

        $test = TestAction::make($action)->table($parcel);

        $expected
            ? $component->assertActionVisible($test)
            : $component->assertActionHidden($test);
    }
})->with([
    'packed, not gone' => [ShipmentStatus::Pending, ['dispatched', 'cancelled']],
    // Not cancellable. This is the row that matters: the goods are in the world.
    'dispatched' => [ShipmentStatus::Dispatched, ['delivered']],
    'delivered' => [ShipmentStatus::Delivered, []],
    'called off' => [ShipmentStatus::Cancelled, []],
]);

it('dispatches a parcel and raises the count that never falls', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest(quantity: 5), quantity: 2);

    Livewire::test(ListShipments::class)
        ->callAction(TestAction::make('dispatch')->table($parcel))
        ->assertHasNoActionErrors();

    $line = FulfillmentLine::query()->where('order_line_id', ghostLine())->firstOrFail();

    expect($parcel->refresh()->status)->toBe(ShipmentStatus::Dispatched)
        ->and($parcel->dispatched_at)->not->toBeNull()
        ->and($line->dispatched_quantity)->toBe(2)
        ->and($line->committed_quantity)->toBe(2)
        // Three of five are still to pick: two went into this parcel and none
        // has been called off.
        ->and($line->remainingQuantity())->toBe(3)
        ->and($line->undispatchedQuantity())->toBe(0);
});

it('takes the dispatch button away the moment the goods have gone, so a double click has nothing to press', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest(), quantity: 2);

    $component = Livewire::test(ListShipments::class);

    $component->callAction(TestAction::make('dispatch')->table($parcel))
        ->assertHasNoActionErrors();

    // Asserted at the level that is actually observable. `callAction()` runs
    // `assertActionVisible()` first, so a second `callAction` here would fail
    // *because the design works* — the button is already gone.
    $component->assertActionHidden(TestAction::make('dispatch')->table($parcel));

    $line = FulfillmentLine::query()->where('order_line_id', ghostLine())->firstOrFail();

    // The count moved once. Reporting the same goods leaving twice is how a
    // fulfilled count ends up ahead of the warehouse.
    expect($line->dispatched_quantity)->toBe(2);
});

it('will not cancel a parcel that has left, by any route it offers', function () {
    $this->actorForTeam(TEAM);

    $parcel = parcelAt(ShipmentStatus::Dispatched);

    // Three statements of one rule. The machine has no such edge; the policy
    // refuses the ability, because it asks the domain's own `isCancellable()`;
    // and the panel therefore renders no button.
    expect($parcel->status->canTransitionTo(ShipmentStatus::Cancelled))->toBeFalse()
        ->and($parcel->isCancellable())->toBeFalse();

    Livewire::test(ListShipments::class)
        ->assertActionHidden(TestAction::make('cancel')->table($parcel));

    // And the page says why, in the words a person arrives asking about, rather
    // than leaving a missing button to be interpreted.
    expect(ShipmentResource::cancellationNote($parcel))
        ->toContain('there is no void')
        ->toContain('that is a return')
        ->toContain('never dispatched');
});

it('calls a pending parcel off and puts the goods back on the shelf', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest(quantity: 5), quantity: 2);
    $records = captureLog();

    Livewire::test(ListShipments::class)
        ->callAction(TestAction::make('cancel')->table($parcel), ['reason' => 'packed-in-error'])
        ->assertHasNoActionErrors();

    $line = FulfillmentLine::query()->where('order_line_id', ghostLine())->firstOrFail();

    expect($parcel->refresh()->status)->toBe(ShipmentStatus::Cancelled)
        ->and($parcel->cancelled_at)->not->toBeNull()
        // The reservation fell. That is what `committed` is for, and it is the
        // whole reason it is a separate column from `dispatched`.
        ->and($line->committed_quantity)->toBe(0)
        ->and($line->dispatched_quantity)->toBe(0)
        // Nothing was cancelled on the *order*: the goods are owed still, they
        // are just not in this box any more.
        ->and($line->cancelled_quantity)->toBe(0)
        ->and($line->remainingQuantity())->toBe(5);

    $written = json_encode($records(), JSON_THROW_ON_ERROR);

    // The domain's own logger copies the reason into
    // `fulfillment.shipment_cancelled`. What lands there is a slug.
    expect($written)->toContain('packed-in-error')
        ->and($written)->toContain('fulfillment.shipment_cancelled');
});

it('will not call a parcel off without a reason from the list', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest());

    Livewire::test(ListShipments::class)
        ->callAction(TestAction::make('cancel')->table($parcel), ['reason' => null])
        ->assertHasActionErrors(['reason' => ['required']]);

    expect($parcel->refresh()->status)->toBe(ShipmentStatus::Pending);
});

it('keeps the cancellation vocabulary closed and free of anything a person types', function () {
    // A select over these and not a text box. The domain's event logger copies
    // this value straight into a log line, and *"rang the shopper on 07700
    // 900000"* is what people type into a box.
    expect(array_keys(ShipmentResource::CANCELLATION_REASONS))->toBe([
        'packed-in-error',
        'out-of-stock',
        'damaged-in-warehouse',
        'duplicate-parcel',
        'address-problem',
        'customer-request',
    ]);

    foreach (array_keys(ShipmentResource::CANCELLATION_REASONS) as $reason) {
        expect($reason)->toMatch('/^[a-z-]+$/')
            ->and(strlen($reason))->toBeLessThanOrEqual(64);
    }
});

it('records delivery without moving a single counter', function () {
    $this->actorForTeam(TEAM);

    $parcel = parcelAt(ShipmentStatus::Dispatched);
    $before = FulfillmentLine::query()->where('order_line_id', ghostLine())->firstOrFail();

    Livewire::test(ViewShipment::class, ['record' => $parcel->reference])
        ->callAction('deliver')
        ->assertHasNoActionErrors();

    $after = FulfillmentLine::query()->where('order_line_id', ghostLine())->firstOrFail();

    expect($parcel->refresh()->status)->toBe(ShipmentStatus::Delivered)
        ->and($parcel->delivered_at)->not->toBeNull()
        // Everything the goods needed accounting for happened when they left.
        ->and($after->committed_quantity)->toBe($before->committed_quantity)
        ->and($after->dispatched_quantity)->toBe($before->dispatched_quantity)
        ->and($after->cancelled_quantity)->toBe($before->cancelled_quantity);
});

it('surfaces the arithmetic refusal with the outstanding quantity in it, and clamps nothing', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest(quantity: 5), quantity: 2);
    $line = FulfillmentLine::query()->where('order_line_id', ghostLine())->firstOrFail();

    // The window a hidden button cannot close. Between this page rendering and
    // the button being pressed, the committed count moved underneath it — a
    // colleague's cancellation of another parcel, a queued release, or the
    // mis-recorded-dispatch correction the runbook describes. Written directly
    // because no legal call can produce it, which is exactly why the guard is
    // checked against the database and not against what the caller loaded.
    $line->forceFill(['committed_quantity' => 0])->save();

    Livewire::test(ListShipments::class)
        ->callAction(TestAction::make('dispatch')->table($parcel))
        ->assertHasNoActionErrors();

    // Refused, and refused whole: the transaction rolled back, so there is no
    // half-dispatched parcel and no counter moved by a trimmed amount.
    expect($parcel->refresh()->status)->toBe(ShipmentStatus::Pending)
        ->and($parcel->dispatched_at)->toBeNull()
        ->and($line->refresh()->dispatched_quantity)->toBe(0);

    // The domain's own words, verbatim, with the number a clamping surface would
    // have silently used instead.
    Notification::assertNotified(
        Notification::make()
            ->title('Not moved')
            ->body('Order line '.ghostLine().' has 0 committed and not yet dispatched, so 2 cannot be moved. Nothing can leave that was never committed, and nothing already gone can be released — that is a return.')
            ->danger(),
    );
});

it('offers no move at all on a finished parcel, and says on the page why not', function () {
    $this->actorForTeam(TEAM);

    $parcel = parcelAt(ShipmentStatus::Delivered);

    Livewire::test(ViewShipment::class, ['record' => $parcel->reference])
        ->assertOk()
        // An empty header is a puzzle; this is the sentence that answers it.
        ->assertSee('None. This parcel is delivered, which is final.')
        ->assertSee('there is no void');

    expect(ShipmentResource::nextMoves($parcel))->toBe(['None. This parcel is delivered, which is final.']);
});

it('says nothing about the destination or the tracking in the notifications it sends', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest(), trackingNumber: 'TRK-SECRET-9001');

    Livewire::test(ListShipments::class)
        ->callAction(TestAction::make('dispatch')->table($parcel));

    // The whole body, pinned. A notification is rendered outside the page the
    // policy guards and is the easiest place for a tracking number or an address
    // to be interpolated "for context".
    Notification::assertNotified(
        Notification::make()
            ->title('Recorded as dispatched')
            ->body('The goods are reported as gone and the dispatched count has risen. That count never falls.')
            ->success(),
    );
});
