<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\ContentActions;

use Modules\Content\Application\ContentPermissions;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\TextTranslator;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * **What Content's screens may offer** (content.md §3: "Presentation never asks the authorizer: a
 * `ContentActionsForReader` query answers what each screen may offer", `AccessDecisionsTest`): for each store
 * where the reader holds any of Content's jobs - and, off, only for whoever may switch stores - which kinds
 * of item they may edit; whether they may manage themes (every store) and redirects (store-free); whether
 * Translate can be offered at all on this server (§1.3: "Translation isn't set up on this server.").
 *
 * The skeleton of PR 2: each screen's own answers (a row's menu, a disabled control's reason) join it with
 * that screen. It reads; it never locks. One permission read a job, and the store directory, for every
 * store at once.
 */
final readonly class ContentActionsForReader
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private TextTranslator $translator,
    ) {}

    /**
     * @return list<StoreContentActions> in the stores' order
     */
    public function stores(): array
    {
        return $this->answer(null);
    }

    public function forStore(string $storeId): ?StoreContentActions
    {
        return $this->answer(strtolower(trim($storeId)))[0] ?? null;
    }

    /** Themes are a Super Admin's, in every store at once (§3). */
    public function mayManageThemes(): bool
    {
        return $this->authorizer->storesWith(ContentPermissions::THEME_MANAGE) === null;
    }

    /** Redirects are a Super Admin's and belong to no store (§3). */
    public function mayManageRedirects(): bool
    {
        return $this->authorizer->storesWith(ContentPermissions::REDIRECT_MANAGE) !== [];
    }

    /** Whether Translate can work on this server: a token is set (§1.3). */
    public function translationReady(): bool
    {
        return $this->translator->isReady();
    }

    /**
     * @return list<StoreContentActions>
     */
    private function answer(?string $only): array
    {
        $held = [];

        foreach (ContentPermissions::jobs() as $permission) {
            $stores = $this->authorizer->storesWith($permission);
            $held[$permission] = $stores === null ? null : array_map(static fn (StoreId $store): string => $store->value, $stores);
        }

        $mayOff = $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== [];
        $kinds = array_filter(ContentPermissions::KINDS, static fn (string $permission): bool => $permission !== ContentPermissions::THEME_MANAGE);
        $actions = [];

        foreach ($this->platform->allStores() as $store) {
            $id = strtolower($store->id);

            if (($only !== null && $id !== $only) || (! $store->isActive && ! $mayOff)) {
                continue;
            }

            $mine = array_keys(array_filter($kinds, static fn (string $permission): bool => $held[$permission] === null || in_array($id, $held[$permission], true)));

            if ($mine === []) {
                continue;
            }

            $actions[] = new StoreContentActions($id, $mine, ! $store->isActive);
        }

        return $actions;
    }
}
