<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource;

/**
 * No header actions, because there is no `CreateAction` to put in them.
 *
 * A parcel is recorded by whatever packs it — a warehouse integration, an API
 * client, a host listener — calling `RecordShipment` with an idempotency key that
 * caller chose, and `ShipmentPolicy::create()` denies the ability outright. A
 * button here would mint a fresh key on every press, which is a second parcel on
 * a double click and the same goods reported as leaving twice.
 */
class ListShipments extends ListRecords
{
    protected static string $resource = ShipmentResource::class;

    public function getSubheading(): string
    {
        return 'Parcels are read-only here apart from the three moves the state machine allows. Nothing on this page can raise or lower a quantity, and a parcel that has been dispatched cannot be called off.';
    }
}
