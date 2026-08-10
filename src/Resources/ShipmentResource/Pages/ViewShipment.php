<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Liberu\Ecommerce\Fulfillment\Filament\Resources\ShipmentResource;

/**
 * The whole parcel, and the moves the state machine will accept from it.
 *
 * A `ViewRecord` rather than an `EditRecord`, which is the difference the rest of
 * this package is built around. The header carries the three transition actions
 * and nothing else — no edit, no delete, no replicate.
 *
 * Each of those is hidden unless `ShipmentStatus::canTransitionTo()` says the
 * edge exists from where this parcel is, so the header of a delivered parcel
 * carries no buttons at all — and the **What can happen next** section says why,
 * in the domain's words, rather than leaving an empty header to be interpreted.
 *
 * This is also the one page in the package that renders the tracking number. It
 * is evidence: readable by somebody the policy has already authorized for this
 * parcel, and nowhere that travels — not a column, not a search, not a filter,
 * not a log line.
 */
class ViewShipment extends ViewRecord
{
    protected static string $resource = ShipmentResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ...ShipmentResource::transitionActions(),
            ShipmentResource::cancelAction(),
        ];
    }
}
