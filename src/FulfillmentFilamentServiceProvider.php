<?php

namespace Liberu\Ecommerce\Fulfillment\Filament;

use Illuminate\Support\ServiceProvider;

/**
 * Named by `module.json` and registered by `ModuleManagerServiceProvider`, never
 * by Composer discovery — the package ships no `extra.laravel.providers`, so an
 * install boots nothing until the deployment names the module.
 *
 * It has nothing to do. The panels belong to the application, and this package
 * contributes to them through {@see FulfillmentPlugin}, which the application
 * attaches. A provider that reached into a panel here would register this
 * module's resources into panels that never asked for them.
 *
 * It registers no policy either, and that is deliberate rather than an omission.
 * The domain module binds a policy for each of its two aggregate roots in its own
 * provider, and a presentation package that bound a second opinion about who may
 * cancel a parcel would be a second answer waiting to disagree with the API and
 * with a queued job. What this package *does* do is refuse, by name, every
 * ability those policies do not publish — see {@see Resources\ShipmentResource}.
 */
final class FulfillmentFilamentServiceProvider extends ServiceProvider {}
