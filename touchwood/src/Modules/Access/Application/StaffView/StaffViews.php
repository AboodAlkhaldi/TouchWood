<?php

declare(strict_types=1);

namespace Modules\Access\Application\StaffView;

use Shared\Domain\ValueObject\StoreId;

/**
 * The staff view's passes (spec §1.11): a staff member looking at the shop from the panel, as
 * themselves, with no customer and no ordering.
 *
 * A pass is a row and a random token in the browser's own cookie, kept here only as a hash. It is
 * read beside the shop's session, never inside it, so a customer signed in to the shop in the same
 * browser is never touched (§1.8). It belongs to the admin session that opened it.
 */
interface StaffViews
{
    /**
     * A pass for the staff member signed in to this admin request, opening the store's shop; it
     * replaces any pass this admin session opened before, and gives the browser its cookie.
     */
    public function open(string $staffId, StoreId $store, int $sessionVersion): void;

    /**
     * The pass this shop request carries, if it still holds: its staff member active, their
     * session version unchanged, not idle past the staff limit and within the staff maximum of
     * that admin sign-in. A pass that no longer holds is ended here. Read once per request.
     */
    public function current(): ?StaffViewPass;

    /** Ends the pass this browser carries, if any: leaving it, or signing in as a customer. */
    public function endHere(): void;

    /** Ends the passes opened from the admin session of this request, which is ending. */
    public function endForThisAdminSession(): void;

    /** Ends every pass of the staff member: signed out everywhere. */
    public function endAllOf(string $staffId): void;
}
