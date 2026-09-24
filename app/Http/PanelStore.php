<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The store the admin panel is currently on (frontend.md §2.2).
 *
 * The panel carries no store in its URLs: the store is remembered on the account and shown in the
 * header, and a screen that is store-free simply ignores it. **Access decides which store that is**
 * — it is the staff member's own, and Access keeps it — and fills this in when it builds the page.
 *
 * It is held here rather than in Access because every module's screens need it. Platform's settings
 * apply to the store in the header, and Catalog's and Sales's will too; a module may not reach into
 * Access to ask, since the dependency only runs the other way (deptrac.yaml).
 *
 * Not the shared `StoreContext`, deliberately. That one makes every store-scoped query filter by it,
 * and in the panel such a query throws today — loudly, which is how a mistake gets found. Filling it
 * would turn that into quietly reading one store's rows (owner, 2026-09-24).
 *
 * One request, one store: bound per request, so nothing here outlives the page it was set for.
 */
final class PanelStore
{
    private ?string $storeId = null;

    /**
     * Null when this person has no store at all — a Super Admin who has not chosen one, or an
     * account that reaches every store.
     */
    public function id(): ?string
    {
        return $this->storeId;
    }

    public function set(?string $storeId): void
    {
        $this->storeId = $storeId;
    }
}
