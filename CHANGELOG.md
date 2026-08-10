# Changelog

All notable changes to this package are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and this package adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-08-10

First release.

### Added

- `FulfillmentPlugin`, attached per panel by the application. Nothing registers globally.
- `FulfillmentRequestResource` — a list and a view page for what each order still owes, scoped to the
  actor's team, with the outstanding count, the accounting in words and the destination on the page.
- `ShipmentResource` — a list and a view page for parcels, with the carrier, the service level, the
  carriage cost in integer minor units, the destination and the tracking number.
- The state machine as actions: **Record as dispatched**, **Record as delivered** and **Call this
  parcel off**, each offered only when `ShipmentStatus::canTransitionTo()` says the machine will
  accept it and the gate agrees.
- A **What can happen next** section on a parcel that states the legal moves and whether calling it
  off is available, so a missing button is never left to be interpreted.
- Read-only relation managers for the lines of demand, the parcels raised against a request, and the
  contents of a parcel — all three extending `WarehouseRelationManager`.
- Filters for parcels in transit, for parcels in transit over a week, and for orders that still have
  goods to pack — the last calling the domain's own query rather than repeating its arithmetic.
- `docs/domain.md`, `docs/adoption.md`, `docs/runbook.md`.

### Decided

- **No editable counter field anywhere, and nothing that could lower a dispatched count.** `committed`
  may fall and `dispatched` may not — that is why they are two columns — so the whole source tree
  constructs exactly one form input, and it is the cancellation reason. A test greps for it.
- **Over-shipping is refused, never clamped.** There is no quantity input here to trim, and the
  domain's refusal is surfaced verbatim with the outstanding quantity in it. A form that silently
  capped a quantity would turn a loud failure into a partial dispatch nobody is told about.
- **There is no void.** A dispatched parcel cannot be called off: no button, no edge in the machine,
  and the policy refuses the ability. The page says why in the domain's own words — if it comes back
  that is a return, and if it never left it was never dispatched, which is a data correction in the
  runbook.
- **An action is offered only when the machine will accept it.** Thirteen of the sixteen ordered
  pairs are illegal, including all four self-transitions, and the panel keeps no list of its own — it
  asks the domain. The legal set is pinned in a test, so a domain change fails this repository too.
- **Delivery gates on the ownership answer the policy publishes**, because the domain names no
  separate ability for a move that shifts no counter. Asking for one with no method behind it would
  be asking a question nothing answers, and a test asserts it is not published.
- **Recording a parcel is not offered.** Its idempotency key belongs to its caller, and a button
  minting a fresh one per press writes a second parcel on a double click.
- **Releasing a line and re-addressing a request are not offered.** Neither policy publishes an
  ability for either, and a reroute belongs to one parcel rather than to an order.
- **Every ability neither policy publishes is refused by name**, on both resources and on all three
  relation managers, because an unanswered gate is permissive and Filament returns *allow* when a
  present policy has no method for the ability asked about.
- **All three relation managers are `isReadOnly()` unconditionally**, which Filament consults before
  any policy — sidestepping both an unanswered gate on two tables that have no policy at all, and a
  policy typed against one model being handed another. `canAssociate` and `canDissociate` are refused
  by name for the same reason.
- **A parcel is bound by its public reference and never by its id**, and the tracking number appears
  on one guarded page and in no column, search, filter, notification or log line.
- **No carrier name anywhere in `src/`.** A carrier is a string the host supplies; a test greps for
  seventeen of them on a word boundary.

[0.1.0]: https://github.com/liberusoftware/module-ecommerce-fulfillment-filament/releases/tag/0.1.0
