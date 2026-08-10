# What this surface shows, and what it refuses

Nothing here is a summary of the code. It is the argument the code implements, for the decisions a
reviewer would otherwise have to reconstruct from a diff.

---

## 1. Two resources, and they are exactly the two tables with a policy

The domain registers a policy for `FulfillmentRequest` and one for `Shipment`, and none at all for
`ecommerce_fulfillment_lines` or `ecommerce_fulfillment_shipment_lines`. That is not an omission on
its part; it is where the aggregate roots are, and its own documentation says a presentation package
must make the other two read-only.

So this package ships two resources and three relation managers, and the split follows the policies
exactly. Neither table of lines carries a `team_id` — tenancy lives on the request and on the parcel,
which is where the policies read it — so any top-level list of either would be a cross-tenant list by
construction. **When a table cannot be scoped, refusing to surface it beats surfacing it with
guards.**

The relation managers answer `isReadOnly()` unconditionally, and that is not belt-and-braces.
Filament consults `isReadOnly()` **before** any policy, which matters because of what asking would
do:

- Nothing is registered for those two classes, so the gate is unanswered — and an unanswered gate is
  permissive. A model with no policy is exposed, not safe. This fleet has shipped that leak three
  times.
- If somebody later registered a root's policy for one of them to "fix" that, the gate would hand the
  wrong class to a parameter typed against the root, and the policy would raise a `TypeError` **from
  inside itself**. That is a five-hundred, not a refusal, and a refusal is what was wanted.

Every ability is then also refused by name, `canAssociate` and `canDissociate` first among them: they
are live on a `hasMany` relation manager and default **open**, and an associate on one of these
relations is how one merchant's line ends up filed against another merchant's parcel — with the
counters then moved against the wrong demand.

---

## 2. The counters are the whole product, so they are shown and never typed

```
quantity              what the order line owes this module
committed_quantity    sitting in a live parcel, dispatched or not
dispatched_quantity   physically gone
cancelled_quantity    will never ship
```

```
committed + cancelled ≤ quantity     nothing may be over-shipped
dispatched ≤ committed               nothing may leave uncommitted
```

**`committed` may fall. `dispatched` may not.** A commitment is a reservation, and calling off a
parcel that never left puts the goods back on the shelf. A dispatched quantity is a fact — it is the
number reported outward as fulfilled, and a running total that can fall is one nobody can audit.

That distinction is why there are two columns rather than one, and it is why this package has:

- **no editable counter field anywhere.** The whole source tree constructs exactly one form input and
  it is the cancellation reason. A test greps for that rather than trusting this paragraph.
- **no action, bulk operation or page that could lower a dispatched count.** Nothing in the domain
  lowers it either; a field here would be the only thing in the system that could, and it would do it
  from a request array.

There is deliberately **no status column on a line**, in the schema or here. A line of five can be
two dispatched, one sitting in a packed parcel, one cancelled and one still to pick at the same
instant, and no single word says that. So the panel says it in a sentence — *"1 to pick, 2 packed and
waiting, 2 dispatched"* — and shows the counters beside it.

Both derived counts come from the domain's own methods. `remainingQuantity()` and
`undispatchedQuantity()` are published precisely so that no consumer derives one and gets it wrong,
and this is a consumer. Nothing here subtracts.

---

## 3. Over-shipping is refused, never clamped

There is no quantity input in this package at all, so there is nothing here to cap. That is the
strongest version of the rule: a form with a `maxValue` is a form that trims, and trimming turns a
loud failure into a partial dispatch nobody is told about — in the one place, a quantity of physical
goods, where a wrong number is a loss.

Where the domain refuses a move because the arithmetic does not permit it, the refusal is surfaced
**verbatim**:

> Order line 9100501 has 0 committed and not yet dispatched, so 2 cannot be moved. Nothing can leave
> that was never committed, and nothing already gone can be released — that is a return.

The outstanding quantity is in that sentence. It is the number the operator needs, and it is exactly
the number a clamping surface would have silently used instead. The whole move is one transaction, so
a refusal leaves no half-dispatched parcel.

The case reaches a person through a window a hidden button cannot close: the committed count moved
between the page rendering and the button being pressed — a colleague's cancellation, a queued
release, a data correction. The action re-reads the record before calling the domain for that reason.

---

## 4. There is no void, and the page says so

```
pending    → dispatched, cancelled
dispatched → delivered
delivered  → (terminal)
cancelled  → (terminal)
```

**A dispatched parcel cannot be called off.** The panel offers no button, the machine has no edge, and
`ShipmentPolicy::cancel()` refuses the ability because it asks the domain's own `isCancellable()`.
Three statements of one rule, and a test asserts all three.

The reason is not caution. A dispatched parcel is in the world: somebody has it, a courier scanned
it, and the quantity has already been reported outward as fulfilled. Cancelling would ask this module
to write down that goods which physically left never left, and the counters would then disagree with
the warehouse in the direction that ships free stock.

The two real situations both have owners and neither is here:

- **It comes back.** That is a return, and it belongs to another module.
- **It never actually left.** Then it was never dispatched, and what happened is a mis-recorded
  fact — a data correction, made by whoever made the mistake, not a domain operation. See
  [runbook.md](runbook.md).

A missing button is a puzzle, and this is the refusal people arrive asking about. So the parcel's
page carries a **Calling it off** entry that says all of it in the domain's own words, rather than
leaving the absence to be interpreted.

---

## 5. Which moves are offered, and how offerability is decided

Three questions, and the panel answers none of them itself.

1. **Does the edge exist from here?** `ShipmentStatus::canTransitionTo($to)`. The panel keeps no list
   of legal pairs, which matters more than it looks: thirteen of the sixteen ordered pairs are
   illegal, including all four self-transitions, and asking the machine excludes every one of them
   without this file knowing which they are.
2. **May this person use it?** The gate.
3. **Would the domain accept it right now?** The record is re-read and the domain is called; whatever
   it refuses with is what the operator is shown.

`tests/Feature/TransitionTest.php` pins the legal set to exactly three pairs, so **adding an edge to
the domain fails this repository too** and somebody has to decide whether it needs a button — rather
than the edge quietly existing with no way to use it.

### The three abilities, and the one the domain does not publish

`dispatch` and `cancel` are separate abilities on `ShipmentPolicy`, because reporting goods as gone
and putting them back on the shelf are different-sized mistakes. `cancel` is additionally gated on
`isCancellable()`.

**Delivery is the one move with no ability of its own**, and this package does not invent one.
Recording a handover moves no counter — everything was accounted for when the goods left — so the
domain names no separate ability, and asking for `deliver` would be asking a question nothing
answers. Laravel denies an unanswered ability on a *present* policy, so a button gated on it would
never appear; Filament's own helper answers the opposite way for a *missing* method, which is why
guessing at either is wrong. The deliver action gates on the ownership answer the policy does
publish — `view` — plus the transition table. A test asserts that `deliver` is not a published
ability, so nobody later mistakes the current arrangement for an oversight.

### A double click

The first press moves the parcel, the edge stops existing, and the button is gone before the second
press has anything to hit. The test asserts that at the level that is actually observable — the
button being hidden — because `callAction()` runs `assertActionVisible()` first, so a second
`callAction` would fail *because the design works*.

---

## 6. Recording a parcel is not offered

`ShipmentPolicy::create()` is permanently false, and this resource restates it.

A parcel is *recorded*, from an input carrying an idempotency key **the caller supplies**, and that
key is the whole guarantee that one van leaving is one row. A button minting a fresh key on every
press writes a second parcel on a double click and reports the same goods leaving twice — which puts
an order's fulfilled count ahead of the world.

Packing belongs to whatever knows it packed something: a warehouse integration, an API client, a host
listener. Each of those has a key to pass. A panel does not.

The same argument, one level up: a fulfillment request is raised from an order through
`RequestFulfillment`, keyed on the order id, one row per order guaranteed at the index. There is no
such thing as one somebody typed in.

---

## 7. What this package deliberately does not surface

**Releasing a line.** `ReleaseLine` is how the host tells this module that some of an order line will
never ship, and it is the inbound half of the boundary. Neither policy publishes an ability for it,
and `FulfillmentRequestPolicy::update()` is false — so a button here would be this package inventing
an authorization answer the domain declined to give. Cancelling belongs to the module that owns
orders, and the host wires the two together. [adoption.md](adoption.md) carries the listener.

**Re-addressing a request.** Genuinely missing from the domain policy, and missing on purpose: a
reroute belongs to one parcel, not to an order. An order does not get rerouted, a box does. The
request's destination is shown as what it is — a default a parcel inherits.

**Any money owed.** A parcel knows what carriage cost. What a shopper paid for delivery is a line on
their order, priced before anybody decided what goes in which box, and it is not this module's to
show.

**Rate shopping, quotes, labels and transit estimates.** Another module's, and the domain refuses the
columns by name.

**A supplier as anything but two strings.** `supplier_id` and `supplier_reference` are shown as an
identifier and somebody else's document number. No supplier model, no supplier relation, no supplier
logic — sourcing is a decision made before this one.

**Returns.** Everything after delivery.

**Any surface at all for the two tables of lines outside their parents.** See §1.

---

## 8. Evidence, and where it may travel

A tracking number identifies a parcel to a carrier. It is `$hidden` on the domain's model, absent
from its read model's array form, absent from its telemetry, not indexed, and there is no query that
takes one.

This package shows it on **one page** — the parcel's own, which `ShipmentPolicy::view()` guards.
Hiding it there would defeat the point of storing it: somebody chasing a stuck parcel is exactly who
should be able to read it. The rule is about where it may *travel*, not about who may read it on a
guarded page.

So it is:

- **not a column**, so it is not in a list a screenshot can carry;
- **not searchable and not filterable** — a search term and a filter's state are both persisted into
  the query string, which every proxy and access log on the path writes down;
- **never in a notification body**, which renders outside the page the policy guards;
- **never in a log line**, and neither is a destination.

A destination gets the same treatment for the plainer reason that it is somebody's home address.

**A parcel is bound by its `reference`, never by its id.** The domain mints that reference from the
CSPRNG precisely so an incrementing id does not end up in a URL, and a URL is the part of a page that
gets pasted into a support ticket.

**The cancellation reason is a `Select` over six slugs.** `TransitionShipment` hands it to
`ShipmentCancelled` and the domain's logger copies it straight into a log line — the domain's own
docblock asks a surface for a select on exactly those grounds. A free-text field next to an event
logger is where a shopper's telephone number gets typed.

---

## 9. Provider neutrality

There is no carrier name anywhere in `src/`, including in the fixtures. A carrier is a string, a
service level is a string, and the host's integration knows what it signed with. A test greps for
seventeen of them on a word boundary — the same shape the domain uses.

A package that knew the names would have to be released the day a merchant signs with somebody else,
and it would have an opinion about which of them are worth naming.

---

## 10. This package writes nothing

Every change goes through `TransitionShipment`, which is the only domain action it calls. A `save()`
or an `update()` in a presentation package is a second write path with none of the transition table,
none of the counter arithmetic, no timestamp and no event — and on these tables it would be a second
write path around two invariants. A test greps `src/` for eleven write methods and expects none.

---

## 11. Known limitations

- **No packing surface.** See §6. If a deployment wants staff to pack from a screen, the thing to
  build is a surface that generates and holds an idempotency key per packing session — not a create
  button on this resource.
- **An order is a number.** There is no link from a parcel to the order it belongs to, because the
  module that owns orders is not a dependency of this package and there is nothing here to link to. A
  host that installs both can add the link in its own panel.
- **No bulk actions of any kind.** Each parcel is a separate fact about separate goods, and a bulk
  failure part-way through is a set of parcels in unknown states.
- **No scheduled sweep.** Nothing in either package runs on a timer. The stuck-parcel filter is here;
  what to do about what it lists is the host's. See [runbook.md](runbook.md).
