<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\FulfillmentRequestResource;

/**
 * The pick list, and no header actions at all.
 *
 * A request is raised by the host, from an order, through `RequestFulfillment`
 * with an idempotency key keyed on the order — one row per order, guaranteed at
 * the index. There is no such thing as one somebody typed in, and
 * `FulfillmentRequestPolicy::create()` denies the ability outright.
 */
class ListFulfillmentRequests extends ListRecords
{
    protected static string $resource = FulfillmentRequestResource::class;

    public function getSubheading(): string
    {
        return 'What each order still owes. Everything here is read-only: the counters move through the domain\'s own actions, because the arithmetic that keeps them honest lives with them.';
    }
}
