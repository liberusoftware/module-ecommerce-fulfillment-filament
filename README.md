# Fulfillment Administration

Filament administration for [`liberusoftware/ecommerce-fulfillment`](https://github.com/liberusoftware/module-ecommerce-fulfillment).

Warehouse tooling. It shows what each order still owes, every parcel raised against it, and where
each of those parcels has got to. It lets staff move a parcel along the three edges the state machine
allows, and change nothing else — and the counters, which are the whole point, are readable
everywhere and typeable nowhere.

```bash
composer config repositories.ecommerce-fulfillment vcs https://github.com/liberusoftware/module-ecommerce-fulfillment
composer require liberusoftware/ecommerce-fulfillment-filament
```

```dotenv
MODULES_ENABLED=ecommerce-fulfillment,ecommerce-fulfillment-filament
```

```php
use Liberu\Ecommerce\Fulfillment\Filament\FulfillmentPlugin;

$panel->plugins([
    FulfillmentPlugin::make(),
]);
```

Full instructions, including what the host has to supply, are in [docs/adoption.md](docs/adoption.md).

## What warehouse staff can do

| | |
| --- | --- |
| Read what an order still owes, line by line, with all four numbers | Yes |
| Read every parcel raised against an order, and what is in each | Yes |
| Read a parcel's own destination, its carrier, its service level and its tracking number | Yes |
| **Record a parcel as dispatched** | Yes |
| **Record a parcel as delivered** | Yes |
| **Call off a parcel that has not left**, with a reason from a fixed list | Yes |
| Call off a parcel that **has** left | **No.** There is no void — see below |
| Record a new parcel | **No** — the domain policy denies it outright |
| Edit or delete a parcel, a request, or anything hanging off either | **No** — both domain policies deny it outright |
| Type a quantity anywhere, or edit any counter | **No.** There is no quantity input in this package |
| Re-address an order | **No.** An order does not get rerouted, a box does |

Every one of those refusals is argued in [docs/domain.md](docs/domain.md).

## The counters are the point

Each line of demand carries three counters and the domain derives two more from them:

```
quantity              what the order line owes this module
committed_quantity    sitting in a live parcel, dispatched or not      — may fall
dispatched_quantity   physically gone                                  — never falls
cancelled_quantity    will never ship
```

**`committed` may fall and `dispatched` may not, and that is why they are two columns.** Calling off
a parcel that never left is releasing a reservation; a dispatched quantity is a fact, and a running
total that can fall is one nobody can audit. `dispatched <= committed` is the invariant.

So: there is **no editable counter field anywhere in this package**, and **no action, bulk operation
or form that could lower a dispatched count**. The three counters and both derived counts are shown
as columns and summarised as a sentence — *"1 to pick, 2 packed and waiting, 2 dispatched"* — because
a line of five can be all of those at once and no single status word says that.

**Over-shipping is refused, never clamped.** There is no quantity input here to trim, and where the
domain refuses a move because the arithmetic does not permit it, the refusal reaches the operator
verbatim with the outstanding quantity in it. A form that silently capped a quantity would turn a
loud failure into a partial dispatch nobody is told about.

## There is no void

A dispatched parcel cannot be called off. Not by a button, not by a status field, not by a bulk
action.

The parcel is in the world: somebody has it, and the quantity has already been reported outward as
fulfilled. The two real situations both have owners and neither is here — if it comes back that is a
**return**, and if it never actually left then it was never dispatched and what happened is a
**mis-recorded fact**, corrected by whoever made the mistake. The runbook says how.

That sentence is on the page, in the parcel's own **Calling it off** entry, rather than being left to
be inferred from a missing button.

## Which moves are offered, and how it decides

Three edges exist, and the panel keeps no list of them:

```
pending    → dispatched     "Record as dispatched"
pending    → cancelled      "Call this parcel off"
dispatched → delivered      "Record as delivered"
```

Every button asks `ShipmentStatus::canTransitionTo()` before it renders, and then asks the gate. That
excludes all thirteen illegal ordered pairs — including every self-transition — without this package
knowing which they are, and a test pins the legal set so a change to the domain fails this repository
too. A button that always throws is a worse surface than no button.

`dispatch` and `cancel` are separate abilities on the domain's policy, because reporting goods as
gone and putting them back on the shelf are different-sized mistakes. `cancel` is additionally gated
on the domain's own `isCancellable()`, so a staff member holding the ability still cannot get round
the boundary. Delivery moves no counter and the domain publishes no separate ability for it, so this
package does not invent one: it gates on the ownership answer the policy does publish.

## What it deliberately keeps out of reach

A tracking number is evidence. It is readable on the parcel's own page, which the policy guards —
that is what evidence is for. What this package never does is let it travel somewhere with weaker
access control:

- **It is not a column, not searchable and not filterable.** A table's search term and its filter
  state are both persisted into the URL, and a query string is written into every access log on the
  path. The only searchable columns here are the parcel's public reference and the order number.
- **It never reaches a log line**, and neither does a destination.
- **No notification body carries one**, or an address.
- **A parcel is bound by its reference and never by its id**, because an incrementing id in a URL is
  an enumeration of everybody else's parcels.
- **The cancellation reason is a select, not a text box.** The domain's own logger copies that value
  into a `fulfillment.shipment_cancelled` log line, and a text box next to an event logger is where
  somebody types a shopper's telephone number.

`tests/Feature/SecurityTest.php` asserts each of these.

## No carrier names

There is not one anywhere in `src/`, including in the fixtures. A carrier is a string and a service
level is a string; the host's integration knows what it signed with. A test greps for seventeen of
them on a word boundary.

## Compatibility

PHP 8.5, Laravel 13, Filament 5. The plugin does not require the panel to be tenant-aware.

## Documentation

- [docs/domain.md](docs/domain.md) — what this surface shows, what it refuses, and why each refusal.
- [docs/adoption.md](docs/adoption.md) — installing, enabling, attaching, and what the host supplies.
- [docs/runbook.md](docs/runbook.md) — the questions this panel exists to answer, in order, including
  the mis-recorded dispatch.

## Licence

MIT. See [LICENSE.md](LICENSE.md).
