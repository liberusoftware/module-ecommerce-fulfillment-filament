<?php

use Filament\Actions\Testing\TestAction;
use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages\ListFulfillmentRequests;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages\ViewFulfillmentRequest;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\RelationManagers\LinesRelationManager;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ListShipments;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages\ViewShipment;
use Liberu\Ecommerce\Fulfillment\Filament\Support\Accounting;
use Liberu\Ecommerce\Fulfillment\Filament\Support\Address;
use Liberu\Ecommerce\Fulfillment\Filament\Support\Amount;
use Liberu\Ecommerce\Fulfillment\Models\FulfillmentLine;
use Livewire\Livewire;

// What a keyboard and a screen reader get out of these surfaces. The two things
// worth a test are the two that regress silently: a state rendered as colour or
// as an icon with no words, and an action whose only affordance is an icon with
// no accessible name. Both look correct in a screenshot.

it('gives every action it offers an accessible name as well as an icon', function () {
    $actions = [...ShipmentResource::transitionActions(), ShipmentResource::cancelAction()];

    $labels = [];

    foreach ($actions as $action) {
        // An action carrying an icon is an action Filament may render as an icon
        // button, and an icon button with no label is a control a screen reader
        // announces as "button".
        expect($action->getIcon())->not->toBeNull($action->getName());

        $labels[] = (string) $action->getLabel();
    }

    // Named for the move rather than for the destination. "Dispatched" is a
    // state; "Record as dispatched" is what pressing the button does.
    expect($labels)->toBe(['Record as dispatched', 'Record as delivered', 'Call this parcel off']);
});

it('says what state a parcel is in, in words, rather than in a badge colour', function () {
    $this->actorForTeam(TEAM);

    $pending = parcelAt(ShipmentStatus::Pending, orderId: 9_000_541);
    $dispatched = parcelAt(ShipmentStatus::Dispatched, orderId: 9_000_542);
    $cancelled = parcelAt(ShipmentStatus::Cancelled, orderId: 9_000_543);

    // Amber and grey are the same badge to a screen reader and to anybody who
    // cannot separate the two. "Packed, not gone" is also the phrase that says
    // what `pending` actually means to a warehouse, which "Pending" does not.
    Livewire::test(ListShipments::class)
        ->assertOk()
        ->assertTableColumnFormattedStateSet('status', 'Packed, not gone', $pending)
        ->assertTableColumnFormattedStateSet('status', 'Dispatched', $dispatched)
        ->assertTableColumnFormattedStateSet('status', 'Called off', $cancelled);
});

it('says where a line of demand has got to, because no single word can', function () {
    $this->actorForTeam(TEAM);

    // Five ordered. Two go into a parcel that leaves, two into a parcel that is
    // packed and waiting, and one is still to pick — all true at the same
    // instant, which is why there is no status on a line.
    $request = aRequest(quantity: 5);

    $gone = aParcel($request, quantity: 2, key: 'parcel-gone');
    aParcel($request->refresh(), quantity: 2, key: 'parcel-waiting');

    Livewire::test(ListShipments::class)
        ->callAction(TestAction::make('dispatch')->table($gone));

    $line = FulfillmentLine::query()->where('order_line_id', ghostLine())->firstOrFail();

    expect(Accounting::ofLine($line))->toBe('1 to pick, 2 packed and waiting, 2 dispatched');

    Livewire::test(LinesRelationManager::class, [
        'ownerRecord' => $request->refresh(),
        'pageClass' => ViewFulfillmentRequest::class,
    ])
        ->assertOk()
        // Words, not a bar. A progress bar has no accessible value unless
        // somebody remembers to give it one, and this is the question the page is
        // open to answer.
        ->assertTableColumnStateSet('accounting', '1 to pick, 2 packed and waiting, 2 dispatched', $line)
        ->assertTableColumnStateSet('remaining', 1, $line)
        ->assertTableColumnStateSet('undispatched', 2, $line);

    Livewire::test(ListFulfillmentRequests::class)
        ->assertOk()
        ->assertTableColumnStateSet('accounting', '1 to pick, 2 packed and waiting, 2 dispatched', $request->fresh()->load('lines'));
});

it('says so plainly when a line has nothing to account for', function () {
    // Every count zero is a line of nothing, which the domain allows, and an
    // empty cell cannot be told apart from a column that failed to render.
    $request = aRequest(quantity: 0);

    expect(Accounting::ofRequest($request->fresh()->load('lines')))->toBe('Nothing to account for');
});

it('labels the parts of a destination rather than running them together', function () {
    // Destination shape is whatever arrived with the request, so the labels are
    // derived from the keys. A value that is not a scalar is dropped rather than
    // rendered as the word "Array".
    expect(Address::lines([
        'line1' => '1 High Street',
        'postal_code' => 'LS1 1AA',
        'country' => 'GB',
        'blank' => '',
        'nested' => ['no' => 'thanks'],
    ]))->toBe([
        'Line 1: 1 High Street',
        'Postal Code: LS1 1AA',
        'Country: GB',
    ])
        ->and(Address::lines(null))->toBe([]);
});

it('renders money from the integer without ever dividing', function () {
    // `(int) (19.99 * 100)` is 1998 and `1999 / 100` is where the penny goes.
    // Pad, split, concatenate — string arithmetic the whole way.
    expect(Amount::decimal(1999))->toBe('19.99')
        ->and(Amount::decimal(5))->toBe('0.05')
        ->and(Amount::decimal(0))->toBe('0.00')
        ->and(Amount::decimal(-250))->toBe('-2.50')
        // The exponent travels with the amount rather than being assumed to be
        // two: a zero-exponent currency rendered as 1999 → 19.99 has divided
        // somebody's carriage bill by a hundred.
        ->and(Amount::decimal(1999, 0))->toBe('1999')
        ->and(Amount::decimal(1999, 3))->toBe('1.999')
        // Prefixed with the ISO code and not a symbol: a panel serving several
        // merchants shows several currencies in one column, and two symbols do
        // not tell a screen reader which is which.
        ->and(Amount::format(599, 'GBP'))->toBe('GBP 5.99');
});

it('says a carriage cost was never recorded rather than showing it as nothing', function () {
    $this->actorForTeam(TEAM);

    $parcel = parcelAt(ShipmentStatus::Pending);

    // Zero and "nobody told us" are different facts, and a carriage cost of zero
    // is a claim about a free delivery.
    expect(Amount::of($parcel, null))->toBeNull()
        ->and(Amount::of($parcel, 0))->toBe('GBP 0.00')
        ->and(Amount::of($parcel, 599))->toBe('GBP 5.99');
});

it('heads its computed columns with words rather than with attribute names', function () {
    $this->actorForTeam(TEAM);

    aParcel(aRequest());

    // Filament humanises a column name into a heading when none is given, which
    // is how `shipping_cost_minor` becomes "Shipping cost minor" and
    // `lines_count` becomes "Lines count". Neither is what the column holds, and
    // the label is the only thing keeping them out.
    Livewire::test(ListShipments::class)
        ->assertOk()
        ->assertSee('Parcel')
        ->assertSee('Goods')
        ->assertDontSee('Shipping cost minor');

    Livewire::test(ListFulfillmentRequests::class)
        ->assertOk()
        ->assertSee('Still to pick')
        ->assertDontSee('Lines count');
});

it('states on each list what the surface will and will not let anybody do', function () {
    $this->actorForTeam(TEAM);

    aParcel(aRequest());

    // Refusals inferred from missing buttons are a puzzle. Saying it is one
    // sentence.
    Livewire::test(ListShipments::class)
        ->assertOk()
        ->assertSee('cannot be called off');

    Livewire::test(ListFulfillmentRequests::class)
        ->assertOk()
        ->assertSee('Everything here is read-only');
});

it('says on the parcel page which moves are legal from here and whether calling it off is one', function () {
    $this->actorForTeam(TEAM);

    $parcel = parcelAt(ShipmentStatus::Pending);

    Livewire::test(ViewShipment::class, ['record' => $parcel->reference])
        ->assertOk()
        ->assertSee('Legal moves from here')
        ->assertSee('Dispatched')
        ->assertSee('Available. Nothing in this parcel has left');

    expect(ShipmentResource::nextMoves($parcel))->toBe(['Dispatched', 'Called off']);
});

it('shows the destination it was given and says so when there is none', function () {
    $this->actorForTeam(TEAM);

    $parcel = aParcel(aRequest(), destination: ['line1' => '2 Neighbour Lane', 'city' => 'Leeds']);
    $inherited = aParcel(aRequest(orderId: 9_000_551), key: 'inherited-parcel');

    // A reroute belongs to one box. This parcel's own destination differs from
    // the request's, and nothing else was rewritten.
    Livewire::test(ViewShipment::class, ['record' => $parcel->reference])
        ->assertOk()
        ->assertSee('Line 1: 2 Neighbour Lane')
        ->assertSee('City: Leeds');

    // Given none, it inherits the request's copy.
    Livewire::test(ViewShipment::class, ['record' => $inherited->reference])
        ->assertOk()
        ->assertSee('Line 1: 1 High Street');

    expect($parcel->request->destination)->toBe(['line1' => '1 High Street', 'city' => 'Leeds', 'country' => 'GB']);
});
