<?php

namespace Liberu\Ecommerce\Fulfillment\Filament\Resources;

use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * A relation manager that can be read and nothing else.
 *
 * ## `isReadOnly()` first, and it is not belt-and-braces
 *
 * Filament consults `isReadOnly()` **before** it consults any policy. That
 * ordering is what makes it the right guard here rather than a redundant one,
 * because of what asking the policy would actually do.
 *
 * The domain registers policies on its two aggregate roots and on nothing else,
 * and its own documentation says a presentation package must make the other two
 * tables read-only. Every method on those policies is typed against the root it
 * belongs to. A relation manager's default authorization asks the gate about the
 * **related** model — a line of demand, a row saying how much went in a box — and
 * two things can happen, neither of which is a denial:
 *
 * - No policy is registered for those classes, so nothing answers, and the
 *   unanswered case is permissive. A model with no policy is exposed, not safe.
 *   This fleet has shipped that leak three times.
 * - If somebody later registers a root's policy for one of them to "fix" that,
 *   the gate hands the wrong class to a typed parameter and the policy raises a
 *   `TypeError` **from inside itself** — a five-hundred, not a refusal, and a
 *   refusal is what was wanted.
 *
 * Returning `isReadOnly()` unconditionally sidesteps both, because the question
 * is answered before either can happen.
 *
 * ## And then every ability by name anyway
 *
 * Filament's authorization helper returns *allow* when a **present** policy has
 * no method for the ability asked about, so a partial policy is the same hazard
 * as no policy and harder to see. Each ability below is therefore answered by
 * name rather than inferred from a table that happens to carry no create action
 * today. The next person to add an action to one of these tables should find the
 * door already shut.
 *
 * `canAssociate` and `canDissociate` are the two that matter most and the two
 * least likely to be noticed: they are live on a `hasMany` relation manager and
 * they default **open**. An associate on one of these relations is how a line of
 * one merchant's order ends up filed against another merchant's parcel — the
 * counters would then be moved against the wrong demand, and the count reported
 * outward as fulfilled would be a number about somebody else's goods.
 *
 * ## Why read-only rather than merely restricted
 *
 * **Every column under here is a counter or a quantity, and a counter is not a
 * field.** The three on a line of demand move through the domain's own actions
 * and nowhere else, because the arithmetic that keeps them honest —
 * `committed + cancelled ≤ quantity`, and `dispatched ≤ committed` — lives with
 * them. A panel that wrote one directly would be writing round both invariants,
 * in the one place a wrong number is a physical loss.
 *
 * `dispatched_quantity` is the sharpest case. It is the count reported outward as
 * fulfilled, it is append-only by construction, and there is no move anywhere in
 * the domain that lowers it. An editable field here would be the only thing in
 * the system that could, and it would do it from a request array.
 *
 * ## The one method deliberately not defined
 *
 * `fill()`. Narrowing a parent's public method to `private` on a relation manager
 * is a fatal at class load rather than a test failure, and `fill()` is the one
 * that has bitten. Everything here matches the parent's `protected` visibility
 * and its parameter types exactly.
 *
 * Authorization for reading is the owner's: `view` on the request or on the
 * parcel these rows hang off. Tenancy is therefore the policy's single answer
 * rather than a second one written here.
 */
abstract class WarehouseRelationManager extends RelationManager
{
    /**
     * Unconditional, and not a call to `parent::isReadOnly()`.
     *
     * The parent answers `true` only when the current panel has been configured
     * with `readOnlyRelationManagersOnResourceViewPages()`, which is the
     * application's setting and not this package's to assume. These tables are
     * read-only wherever this plugin is attached.
     */
    public function isReadOnly(): bool
    {
        return true;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    protected function canViewAny(): bool
    {
        return Gate::allows('view', $this->getOwnerRecord());
    }

    protected function canView(Model $record): bool
    {
        return Gate::allows('view', $this->getOwnerRecord());
    }

    protected function canCreate(): bool
    {
        return false;
    }

    protected function canEdit(Model $record): bool
    {
        return false;
    }

    protected function canDelete(Model $record): bool
    {
        return false;
    }

    protected function canDeleteAny(): bool
    {
        return false;
    }

    protected function canForceDelete(Model $record): bool
    {
        return false;
    }

    protected function canForceDeleteAny(): bool
    {
        return false;
    }

    protected function canRestore(Model $record): bool
    {
        return false;
    }

    protected function canRestoreAny(): bool
    {
        return false;
    }

    protected function canReplicate(Model $record): bool
    {
        return false;
    }

    protected function canReorder(): bool
    {
        return false;
    }

    /*
     * A `hasMany` relation manager offers associate and dissociate rather than
     * attach and detach, and all six are refused: nothing in this package may
     * move a line of demand, a parcel or a shipment line between owners. Goods
     * filed against the wrong demand is the one failure these counters cannot
     * survive.
     */

    protected function canAssociate(): bool
    {
        return false;
    }

    protected function canDissociate(Model $record): bool
    {
        return false;
    }

    protected function canDissociateAny(): bool
    {
        return false;
    }

    protected function canAttach(): bool
    {
        return false;
    }

    protected function canDetach(Model $record): bool
    {
        return false;
    }

    protected function canDetachAny(): bool
    {
        return false;
    }
}
