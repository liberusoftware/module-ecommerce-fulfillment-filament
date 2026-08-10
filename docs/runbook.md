# Runbook

The questions this panel exists to answer, in the order a warehouse asks them — and the two
situations it deliberately cannot answer, with what to do instead.

Nothing in either package runs on a timer. Every sweep below is something a person or the host's own
schedule starts.

---

## 1. What is there to pack?

**To fulfil → Still has goods to pack.**

The filter asks the domain's own query, which compares the columns —
`quantity > committed + cancelled` — rather than reading a stored "remaining". A stored one would be a
fourth number to keep in step with three others, and the first time it drifted nobody would know
which was right.

The **Still to pick** column is the same arithmetic per order. Open a request to see it per line,
along with what is packed and waiting, what has gone and what has been called off.

An order shows *Nothing left to pack* when every unit is in a parcel, gone, or called off. **That is
not the same as everything having shipped** — goods sitting in a packed parcel that has not left are
already committed. The sentence on the page says so, because "complete" next to an undispatched
parcel is exactly the thing that gets misread.

---

## 2. A van has just left. Where do I record that?

**Shipments → the parcel → Record as dispatched.**

The button appears only on a parcel that is packed and not gone, and only for somebody whose team
owns it. Pressing it:

- raises `dispatched_quantity` on every line in the parcel — **and that number never falls again**;
- stamps `dispatched_at`;
- publishes `ShipmentDispatched`, which is what the host's listener turns into a fulfilled count on
  the order line.

Once it has moved, the parcel cannot be called off. See §6.

If the parcel was already recorded as dispatched by a warehouse integration or an API client, the
button is simply not there — the edge no longer exists. That is also what makes a double click safe.

---

## 3. It has arrived. Anything to do?

**The parcel → Record as delivered.**

No counter moves; everything the goods needed accounting for happened when they left. What it does is
stamp the handover, which is when a return becomes possible and when the parcel stops being stuck.

---

## 4. Which parcels are stuck?

**Shipments → In transit for over a week.**

Dispatched, not delivered, and dispatched a while ago. The filter calls the domain's
`scopeInTransitSince` with a bound moment, because `where('dispatched_at', null)` compiles to `is
null` and would list every parcel that has somehow never been stamped rather than the ones that are
late.

What to do with what it lists is the deployment's: chase the courier, tell the shopper, or record the
delivery that did happen and was never entered. The tracking number is on each parcel's own page.

A climbing count for one carrier is usually a depot that has stopped scanning rather than parcels
that are lost.

---

## 5. Something was packed that should not have been

**The parcel → Call this parcel off**, with a reason from the list.

Only available while it is packed and not gone. It lowers `committed_quantity` — the goods go back on
the shelf and can be packed into a different parcel immediately — and it changes nothing on the
order: the demand is still owed.

The reason is a fixed vocabulary rather than a text box, because the domain's logger copies the value
straight into a `fulfillment.shipment_cancelled` log line.

Nothing is deleted, and nothing is refunded. Money going back follows a return, and this is not one.

---

## 6. It shipped and it should not have

**This panel cannot help, and that is deliberate.** There is no void.

Work out which of the two situations you actually have, because they have different owners:

### It left, and it is coming back

That is a **return**. It belongs to the returns module, and the `returned ≤ fulfilled` invariant on
the order line is what makes it possible at all — nothing can come back that never went out. Leave
this parcel as it is: it is a true record of goods that left.

### It never actually left

Then it was never dispatched, and what you have is a **mis-recorded dispatch**. That is a data
correction, not a domain operation, and no action in this module publishes it — an action that did
would be a way to lower a dispatched count, and there must not be one.

Correcting it means, in a maintenance window and with the numbers written down first:

1. Read the parcel's lines and note the quantity against each `fulfillment_line_id`.
2. Lower `dispatched_quantity` on each of those lines by exactly that quantity.
3. Set the parcel's `status` back to `pending` and clear `dispatched_at`.
4. **Reverse the fulfilled count on the order side too.** `ShipmentDispatched` has already fired and
   the host's listener has already raised it. Whoever owns that listener has to undo what it did, or
   the two sides now disagree.
5. Then cancel the parcel properly through the panel if it is not going out, or dispatch it again
   when it does.

Steps 2 and 3 are the reason this is a runbook entry and not a button: a correction that has to be
paired with an undo in another module is a decision a person makes, with the numbers in front of
them.

---

## 7. The shopper has moved. Can I change the address?

**On a parcel, yes — but not through this panel, and not on the order at all.**

A reroute belongs to one box. A parcel carries its own destination, and giving it one applies to that
parcel and rewrites nothing else; a second parcel against the same order may go somewhere else again.
The request's destination is a default, shown as such, and there is deliberately no way to re-address
it — an order does not get rerouted.

Changing where a specific parcel is going is a job for whatever recorded it, which is where the
destination comes in. If it has already been dispatched, the address is the courier's problem now.

---

## 8. Some of an order has been cancelled. Does the warehouse know?

Only if the host told it. The fulfillment module subscribes to nothing.

When an order calls off part of a line, the host calls `ReleaseLine` with the order line id and the
quantity. Without that listener, this panel keeps showing goods to pick for something the shopper
already cancelled — which is the failure mode worth checking first when a picker reports a phantom.

The action is public and it is deliberately not on this panel: neither policy publishes an ability
for it. See [adoption.md §5](adoption.md#5-getting-parcels-into-the-panel-in-the-first-place).

---

## 9. Somebody says a parcel is missing from the list

Three things, in this order:

1. **Whose team is it?** Both resources scope on the actor's `current_team_id`, and both policies
   read the same attribute. A parcel belonging to another team is not hidden, it is not in the query.
2. **Does it belong to anybody?** A `team_id` of null is nobody's. Those rows are invisible here on
   purpose — a list of unowned rows is a list every action would refuse, and worse, it is a leak. Fix
   the `team_id` on the request that raised it.
3. **Is the actor in a team at all?** No team means an empty list, deliberately, and not an error.

---

## 10. Where is the tracking number?

On the parcel's own page, under **The journey**, and nowhere else.

It is not a column, it is not searchable, it is not filterable, it is never in a notification and it
is never in a log line. A search term and a filter's state are both persisted into the query string,
which every proxy and access log on the path writes down. Support looks a parcel up by its
reference — the `SHP-…` string — or by the order.

---

## 11. Turning the domain's telemetry on while investigating

`FULFILLMENT_TELEMETRY=true`, optionally with `FULFILLMENT_TELEMETRY_CHANNEL`. It is a runtime
setting rather than a boot-time one precisely so a deployment can flip it mid-investigation.

Records `fulfillment.shipment_dispatched`, `…_delivered` and `…_cancelled` with identifiers and
counts. No tracking number, no destination, no supplier reference — enough to alert on, useless to
anybody who should not have it. A cancellation is logged at `warning`; the rest at `info`.

Off is the shipped default. A busy warehouse writes thousands an hour, and that is somebody's
retention bill.
