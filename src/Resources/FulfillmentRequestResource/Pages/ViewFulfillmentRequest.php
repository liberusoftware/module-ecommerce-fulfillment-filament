<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource;

/**
 * Everything an order owes, and every parcel raised against it.
 *
 * A `ViewRecord` with **no header actions whatsoever**, which is the honest
 * rendering of a policy that answers false to every write it publishes. The two
 * things a person can do from here are read the demand and walk to a parcel;
 * moving a parcel along happens on the parcel, where the state machine is.
 */
class ViewFulfillmentRequest extends ViewRecord
{
    protected static string $resource = FulfillmentRequestResource::class;
}
