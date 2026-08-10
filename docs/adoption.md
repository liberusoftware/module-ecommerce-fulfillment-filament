# Adoption

Installing this package, enabling it, attaching it to a panel, and what the host has to supply.

## 1. Install

The domain package this presents is **not on Packagist**. Composer honours `repositories` only from
the root manifest, so the entry goes in the *application's* `composer.json`, not in this package's —
this package declares it for its own CI and that declaration does nothing for a consumer.

```bash
composer config repositories.ecommerce-fulfillment vcs https://github.com/liberusoftware/module-ecommerce-fulfillment
composer require liberusoftware/ecommerce-fulfillment-filament
```

That pulls `liberusoftware/ecommerce-fulfillment` with it. When the domain package reaches Packagist,
the `composer config repositories.*` line is the only thing to remove.

## 2. Enable the modules

Installing boots nothing: neither package ships `extra.laravel.providers`, so Composer discovery
finds no provider. `ModuleManagerServiceProvider` registers the provider each `module.json` names,
and only when the deployment asks for it:

```dotenv
MODULES_ENABLED=ecommerce-fulfillment,ecommerce-fulfillment-filament
```

Both, in that order. The presentation package registers nothing of its own — no migrations, no
policies, no config — so enabling it without the domain module gives you two resources with no tables
to query and no gate to ask.

## 3. Migrate

The domain module's four migrations are loaded by `FulfillmentServiceProvider`:

```bash
php artisan migrate
```

Every table carries the `ecommerce_fulfillment_` prefix, because the module invents all four:

`ecommerce_fulfillment_requests`, `ecommerce_fulfillment_lines`,
`ecommerce_fulfillment_shipments`, `ecommerce_fulfillment_shipment_lines`.

**There is no bare-name exception.** The host's `orders` table accreted four columns from this
domain — a carrier, a service, a quote id and a method id — and the module that owns orders refused
all four, because a column on an order can only ever hold the first answer and is wrong from the
second parcel onwards. `ecommerce_fulfillment_shipments` is what those columns should have been.
Leave the host's columns where they are, migrate what you want, and retire them on your own schedule;
nothing in either package reads them.

None of the four carries a foreign key out of the package. `order_id`, `order_line_id`, `team_id`,
`store_id`, `product_id` and `variant_id` are plain indexed columns.

## 4. Attach the plugin to a panel

The application owns its panels; this package never registers itself into one.

```php
use Filament\Panel;
use Filament\PanelProvider;
use Liberu\Ecommerce\Fulfillment\Filament\FulfillmentPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->plugins([
                FulfillmentPlugin::make(),
            ]);
    }
}
```

`module.json` declares the plugin under `presentation.filament.admin`, which is the panel id this
package is tested against — but nothing enforces it. Attach it to whichever panel should carry
fulfillment, and to more than one if that is what the deployment needs.

The panel does **not** need to be tenant-aware. Both resources scope on the actor's
`current_team_id` rather than on `Filament::getTenant()`, and `isScopedToTenant()` is `false`. It
does not need `readOnlyRelationManagersOnResourceViewPages()` either: all three relation managers
answer `isReadOnly()` themselves, because read-only here is this package's rule and not the
application's setting.

## 5. Getting parcels into the panel in the first place

This package shows parcels. Something has to raise the demand and record the boxes, and it is not
this package and not the domain module either.

**The fulfillment module subscribes to nothing.** Listening to another module's event means importing
a class from a sibling package, and it requires none. The **host** is the only place entitled to know
that two modules exist, so the host writes the listeners.

### Raising the demand when an order is confirmed

```php
// app/Listeners/RequestFulfillmentForOrder.php
use Liberu\Ecommerce\Fulfillment\Actions\RequestFulfillment;
use Liberu\Ecommerce\Fulfillment\Data\FulfillmentRequestInput;

class RequestFulfillmentForOrder
{
    public function handle(object $event): void
    {
        app(RequestFulfillment::class)->handle(
            FulfillmentRequestInput::fromOrderArray($event->order->toArray()),
        );
    }
}
```

`fromOrderArray()` reads the **wire shape** — snake_case keys, integer minor units — rather than the
class, because a shape is a contract you can copy and a class is a dependency you have to install. It
takes only `item` lines (a shipping line on an order is money, not goods) and only what is still
outstanding. It is idempotent on `order_id`, so a redelivered queue job returns the existing request.

### Recording a parcel

Whatever packs it calls `RecordShipment` with an idempotency key **it** chose:

```php
app(RecordShipment::class)->handle(new ShipmentInput(
    orderId: $orderId,
    shipmentKey: $packingSessionId,   // the caller's key. This is the whole guarantee.
    lines: [new ShipmentLineInput($orderLineId, $quantity)],
    carrier: config('shipping.carrier'),
    service: 'next-day',
));
```

That is why this panel has no create button: a button would mint a fresh key on every press.

### Raising the fulfilled counter on the order line

The fulfillment module publishes `ShipmentDispatched` and does **not** raise anybody's counter
itself. The host subscribes:

```php
// app/Listeners/AccountForDispatchedGoods.php
class AccountForDispatchedGoods
{
    public function handle(object $event): void
    {
        foreach ($event->shipment->lines as $line) {
            // $line is an order line id and a quantity. Two integers.
            app(AccountForLine::class)->handle(
                $orderLine = /* resolved from $line->orderLineId */,
                LineAccount::Fulfilled,
                $line->quantity,
            );
        }
    }
}
```

It fires on dispatch, not on delivery: *fulfilled* means the goods have left our hands. It fires
**once** per parcel — a redelivered recording replays and announces nothing.

### Telling this module that some of a line will never ship

The other direction, and the one that is easy to forget. When an order cancels part of a line, this
module's copy of the demand does not know, and a warehouse working from a stale copy picks goods for
something the shopper already called off:

```php
app(ReleaseLine::class)->handle($orderLineId, $quantity);
```

**That action is deliberately not on this panel** — neither policy publishes an ability for it. See
[domain.md §7](domain.md#7-what-this-package-deliberately-does-not-surface).

## 6. What the host has to supply

| Thing | Why | What happens without it |
| --- | --- | --- |
| A `current_team_id` on the authenticated user | It is the whole of both policies' tenancy, and the attribute both resource queries scope on | Every list is empty. Not an error — the deliberate answer for an actor working in no team |
| `MODULES_ENABLED` naming both modules | Installation never implies boot | The resources exist as classes and appear nowhere |
| A `team_id` on the requests it raises | A request or a parcel with `team_id` null belongs to nobody, and both policies deny every action on one | Those rows are invisible in this panel, by design. `FulfillmentRequestInput` takes a `teamId`; pass it |
| The two listeners in §5 | Nothing crosses a module boundary by itself | Demand is never raised, and an order's fulfilled count never moves |
| Colour aliases `success`, `warning`, `danger`, `info`, `gray` on the panel | Badge, button and notification colours | Filament's defaults apply |

Optional:

| Thing | Effect |
| --- | --- |
| `FULFILLMENT_TEAM_MODEL`, if the host's team model is not the Jetstream default | The domain resolves it from config at call time and never imports it. Only matters if something eager-loads the `team` relation; nothing in this package does |
| `FULFILLMENT_TELEMETRY=true` | The domain's own event logger starts recording dispatches, deliveries and cancellations. Off by default; a busy warehouse writes thousands an hour. No tracking number, destination or supplier reference is ever written |
| `FULFILLMENT_TELEMETRY_CHANNEL` | Sends those records to a named log channel instead of the default one |

## 7. What it does not bring

- **No way to set a status directly, and no way to type a quantity.** The state machine is actions,
  only the legal ones are offered, and there is no counter field anywhere.
- **No way to create, edit or delete anything.** The only writes are the state machine's three moves.
- **No packing surface.** Recording a parcel needs an idempotency key its caller owns. See
  [domain.md §6](domain.md#6-recording-a-parcel-is-not-offered).
- **No line release, and no re-addressing a request.** See
  [domain.md §7](domain.md#7-what-this-package-deliberately-does-not-surface).
- **No rates, quotes, labels, suppliers, returns, refunds or stock.** None of those is a parcel fact.
  The domain module owns none of them either.
- **No storefront.** Everything here is the warehouse's side.
- **No scheduled sweep.** Nothing in either package runs on a timer; see [runbook.md](runbook.md).

## Upgrading

This is the first release; there is nothing to upgrade from.
