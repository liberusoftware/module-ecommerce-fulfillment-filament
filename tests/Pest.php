<?php

use Illuminate\Support\Facades\Log;
use Liberu\Ecommerce\Fulfillment\Actions\RecordShipment;
use Liberu\Ecommerce\Fulfillment\Actions\RequestFulfillment;
use Liberu\Ecommerce\Fulfillment\Actions\TransitionShipment;
use Liberu\Ecommerce\Fulfillment\Data\FulfillmentLineInput;
use Liberu\Ecommerce\Fulfillment\Data\FulfillmentRequestInput;
use Liberu\Ecommerce\Fulfillment\Data\ShipmentInput;
use Liberu\Ecommerce\Fulfillment\Data\ShipmentLineInput;
use Liberu\Ecommerce\Fulfillment\Enums\ShipmentStatus;
use Liberu\Ecommerce\Fulfillment\Filament\Tests\TestCase;
use Liberu\Ecommerce\Fulfillment\Models\FulfillmentRequest;
use Liberu\Ecommerce\Fulfillment\Models\Shipment;

pest()->extend(TestCase::class)->in('Feature');

/**
 * The order this whole suite ships, and it does not exist.
 *
 * ## The ids are at nine million, on purpose
 *
 * `TestUser::factory()` numbers its users from one, and so does every other
 * factory in the tree. A "stranger's" team id of `2` is an id the actor may
 * genuinely hold, and an authorization test written against one passes for the
 * wrong reason — it proves the actor can see their own row, not that they cannot
 * see somebody else's. Nine-million-and-something cannot collide with anything
 * this suite creates.
 *
 * The *absence* matters as much as the range. No module that owns orders is
 * installed in this suite, no table of orders exists, and these numbers name
 * nothing at all. They are identifiers, which is the only thing the domain ever
 * holds.
 */
const TEAM = 9_000_007;
const OTHER_TEAM = 9_000_008;
const GHOST_ORDER = 9_000_501;

/**
 * The order line id this suite uses for an order.
 *
 * Derived rather than fixed, because `order_line_id` is unique across the whole
 * table: two orders sharing one would mean two requests fighting over the same
 * goods, and the index refuses it.
 */
function ghostLine(int $orderId = GHOST_ORDER): int
{
    return $orderId + 100_000;
}

/**
 * Raise a fulfillment request over an order nothing in this database has heard
 * of, through the domain's own action.
 *
 * Through the action rather than through a factory, so the counters and the
 * request hash are whatever the domain would really have written. A factory is
 * allowed to write a column no production path may; a test that needs the real
 * arithmetic must not use one.
 */
function aRequest(int $orderId = GHOST_ORDER, ?int $teamId = TEAM, int $quantity = 5): FulfillmentRequest
{
    (new RequestFulfillment())->handle(new FulfillmentRequestInput(
        orderId: $orderId,
        lines: [new FulfillmentLineInput(
            orderLineId: ghostLine($orderId),
            quantity: $quantity,
            name: 'Merino Crew',
            sku: 'GHOST-1',
            // A product id no catalogue in this database has heard of, and there
            // never will be one: a packing slip has to keep describing what is in
            // the box after the catalogue has renamed or deleted it.
            productId: 9_000_042,
        )],
        orderNumber: 'ORD-GHOST'.$orderId,
        teamId: $teamId,
        storeId: 9_000_003,
        destination: ['line1' => '1 High Street', 'city' => 'Leeds', 'country' => 'GB'],
    ));

    return FulfillmentRequest::query()->with('lines')->where('order_id', $orderId)->firstOrFail();
}

/**
 * A parcel against that request, with some of the line in it.
 *
 * The carrier and the service level are invented strings, because no real carrier
 * name appears anywhere in this package — including its fixtures. The host's
 * integration knows what it signed with; this module records what it was told.
 */
function aParcel(FulfillmentRequest $request, int $quantity = 2, ?string $key = null, ?string $trackingNumber = null, ?array $destination = null): Shipment
{
    $result = (new RecordShipment())->handle(new ShipmentInput(
        orderId: $request->order_id,
        shipmentKey: $key ?? 'parcel-'.$request->order_id.'-'.($request->shipments()->count() + 1),
        lines: [new ShipmentLineInput(ghostLine($request->order_id), $quantity)],
        carrier: 'haulier-one',
        service: 'next-day',
        trackingNumber: $trackingNumber,
        destination: $destination,
        shippingCostMinor: 599,
        insuredValueMinor: 12500,
        currency: 'GBP',
    ));

    // Held by the id the action returned rather than read back off the relation
    // by position: a `hasMany` collection carries no ordering of its own.
    return Shipment::query()->with('lines')->findOrFail($result->shipment->id);
}

/**
 * A request and one parcel, walked to a given state by legal moves only.
 *
 * There is no factory shortcut into a state here on purpose: the counters have to
 * be what the moves would really have left behind, because the counters are what
 * every assertion in this suite is about.
 */
function parcelAt(ShipmentStatus $status, int $orderId = GHOST_ORDER, ?int $teamId = TEAM, int $quantity = 2): Shipment
{
    $parcel = aParcel(aRequest($orderId, $teamId), $quantity);

    $path = match ($status) {
        ShipmentStatus::Pending => [],
        ShipmentStatus::Dispatched => [ShipmentStatus::Dispatched],
        ShipmentStatus::Delivered => [ShipmentStatus::Dispatched, ShipmentStatus::Delivered],
        ShipmentStatus::Cancelled => [ShipmentStatus::Cancelled],
    };

    foreach ($path as $step) {
        (new TransitionShipment())->handle($parcel, $step);
    }

    return $parcel->refresh()->load('lines');
}

/**
 * Capture what was written to the log, in order.
 *
 * A long closure with `use (&$records)` and not an arrow function: `fn` captures
 * by value at the point it is defined, so it would hand back the empty array this
 * starts as and never see anything the listener appended.
 *
 * @return Closure(): list<array{level: string, message: string, context: array<string, mixed>}>
 */
function captureLog(): Closure
{
    $records = [];

    Log::listen(function ($record) use (&$records) {
        $records[] = ['level' => $record->level, 'message' => $record->message, 'context' => $record->context];
    });

    return function () use (&$records): array {
        return $records;
    };
}
