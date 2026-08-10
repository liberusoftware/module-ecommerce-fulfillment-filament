<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The person at the panel, and which team they are working in.
 *
 * The team is read off the actor rather than off `Filament::getTenant()` because
 * this package does not require the panel to be tenant-aware — an application may
 * attach the plugin to a panel with no tenancy at all, and a null tenant there
 * would silently widen the scope to every merchant. It is also the same attribute
 * both domain policies read, deliberately: a list scoped by one rule and
 * authorized by another shows rows every row action then refuses, which reads as
 * a broken panel rather than as a denied one.
 *
 * Not the user model: no package may name the application's. `getAttribute()` on
 * `Model` is as far as this goes, and a guard that is not one answers null.
 *
 * There is no `id()` here, unlike the sibling package for orders. The domain
 * records no actor against a shipment move — there is no column for one — and a
 * presentation package inventing an audit trail the domain does not keep would be
 * writing a record nothing else can read.
 */
final class PanelActor
{
    public static function teamId(): ?int
    {
        $actor = Auth::user();

        $teamId = $actor instanceof Model ? $actor->getAttribute('current_team_id') : null;

        return is_numeric($teamId) ? (int) $teamId : null;
    }

    /**
     * Narrow a query to the actor's team.
     *
     * The null case is a `whereRaw('1 = 0')` rather than `where('team_id', null)`:
     * the query builder turns a null binding into `is null`, which would list
     * precisely the unowned rows both policies deny every action on. A parcel with
     * `team_id` null belongs to nobody, so a scope that returned them would be a
     * list where every row's actions are greyed out — and a list of other people's
     * parcels is worse than useless, it is a leak.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function scope(Builder $query, string $column = 'team_id'): Builder
    {
        $teamId = self::teamId();

        return $query->when(
            $teamId === null,
            fn (Builder $scoped) => $scoped->whereRaw('1 = 0'),
            fn (Builder $scoped) => $scoped->where($column, $teamId),
        );
    }
}
