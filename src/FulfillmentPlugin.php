<?php

namespace Liberu\Ecommerce\Fulfillment\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource;

/**
 * What this package contributes to a panel the application composes.
 *
 * **Two resources, and they are exactly the two tables that have a policy.** The
 * domain registers one for `FulfillmentRequest` and one for `Shipment`, and
 * registers none for the lines of demand or for the rows saying how much of a
 * line went into which box. Those two are relation managers on the roots they
 * hang off, never resources of their own.
 *
 * That is not a layout preference. Neither `ecommerce_fulfillment_lines` nor
 * `ecommerce_fulfillment_shipment_lines` carries a `team_id` — tenancy lives on
 * the request and on the parcel, which is where the policies read it — so any
 * top-level list of either is a cross-tenant list by construction, and a model
 * with no policy is exposed rather than safe. When a table cannot be scoped,
 * refusing to surface it beats surfacing it with guards.
 *
 * Listed rather than discovered by a directory scan: discovery reads the
 * filesystem on every boot to rediscover a set that is fixed at release, and a
 * scan rooted at `src/` would also sweep up anything a later version happens to
 * put there.
 */
final class FulfillmentPlugin implements Plugin
{
    public static function make(): self
    {
        return new self();
    }

    public function getId(): string
    {
        return 'liberu-ecommerce-fulfillment';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            FulfillmentRequestResource::class,
            ShipmentResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
