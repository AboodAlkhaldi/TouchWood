<?php

declare(strict_types=1);

namespace Modules\Content\Application\Policies;

use Illuminate\Contracts\Translation\Translator;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\ContentNotFound;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\PolicyKindTaken;
use Modules\Content\Domain\Model\Policy;
use Modules\Content\Domain\Repository\Policies;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Who may change a policy, and the rule its stores keep (flags-2026-11.md S3.1, S3.7, F20; the owner's answers of
 * 2026-11-20):
 *
 * - **Found** only by whoever holds `content.policy.manage` in at least one store it serves (a Super Admin always): an
 *   unknown id, or one serving none of their stores, is not found (`ContentNotFound`, as `ContentAccess` answers for
 *   an item).
 * - **A save touches only the stores ticked** - each one the person must hold the job in, checked by the handler -
 *   **and the stores it takes off**, each one they may work in. A store the policy keeps and the save leaves alone is
 *   not asked: one they do not hold, or one that is off to them, is not on their form (`pickable`).
 * - **Publish, Unpublish and Delete change every store the policy serves**: the job in each (`stillMay` after the lock).
 * - **At least one store.**
 * - **A store has at most one published policy of each kind, Other excepted**: refused naming the store, the kind and
 *   the other policy's title (`PolicyKindTaken`), checked under the policies' lock by whoever publishes or adds a store.
 */
final readonly class PolicyRules
{
    public const string PERMISSION = ContentPermissions::POLICY_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Policies $policies,
        private PlatformApi $platform,
        private Translator $translator,
    ) {}

    /**
     * The stores ticked on a policy's form, lower-cased, each once, in id order; at least one. The caller checks the job
     * in each.
     *
     * @param  list<string>  $storeIds
     * @return list<string>
     *
     * @throws InvalidContentInput
     */
    public function picked(array $storeIds): array
    {
        $ids = array_values(array_unique(array_map(static fn (string $id): string => strtolower(trim($id)), $storeIds)));

        if ($ids === []) {
            throw new InvalidContentInput('stores', InvalidContentInput::TOO_FEW, values: ['min' => 1]);
        }

        sort($ids);

        return $ids;
    }

    /**
     * The stores a policy found by its id serves, once it is one the person may know of: unknown, or serving none of
     * the stores they hold the job in, it is not found.
     *
     * @return list<string>
     *
     * @throws ContentNotFound
     */
    public function served(?Policy $policy): array
    {
        if ($policy === null) {
            throw new ContentNotFound;
        }

        $held = $this->access->storesHeld(self::PERMISSION);

        if ($held !== null && array_intersect($policy->stores, $held) === []) {
            throw new ContentNotFound;
        }

        return $policy->stores;
    }

    /**
     * Of these stores, the ones the person may work in with the job: the ones their form offers, so the only ones a
     * save may take off a policy.
     *
     * @param  list<string>  $storeIds
     * @return list<string>
     */
    public function pickable(array $storeIds): array
    {
        return array_values(array_filter($storeIds, fn (string $id): bool => $this->access->mayWorkIn(self::PERMISSION, $id)));
    }

    /**
     * The job checked in each store: the stores a save takes off a policy, after its row is locked.
     *
     * @param  list<string>  $storeIds
     *
     * @throws ContentError|Unauthorized
     */
    public function authorizeEach(array $storeIds): void
    {
        foreach ($storeIds as $storeId) {
            $this->access->authorize(self::PERMISSION, $storeId);
        }
    }

    /**
     * After the policy's row is locked, for Publish, Unpublish and Delete: a store it serves now that was not checked
     * when it was found (someone added it meanwhile) is checked now.
     *
     * @param  list<string>  $checked
     *
     * @throws ContentError|Unauthorized
     */
    public function stillMay(Policy $locked, array $checked): void
    {
        $this->authorizeEach(array_values(array_diff($locked->stores, $checked)));
    }

    /**
     * Refuses a published policy of a kind one of its stores already has published (Other excepted).
     *
     * @throws PolicyKindTaken
     */
    public function requireOnlyOfKind(Policy $policy): void
    {
        if (! $policy->kind->isUnique()) {
            return;
        }

        foreach ($this->policies->publishedOfKind($policy->kind, $policy->stores, $policy->id) as $other) {
            foreach ($policy->stores as $storeId) {
                if (! in_array($storeId, $other->stores, true)) {
                    continue;
                }

                $locale = $this->translator->getLocale();
                $store = $this->platform->store(StoreId::fromString($storeId));
                $kind = $this->translator->get("content::policies.kinds.{$policy->kind->key()}", [], $locale);

                throw new PolicyKindTaken($store?->name->in($locale) ?? $storeId, is_string($kind) ? $kind : $policy->kind->value, $other->titleIn($locale));
            }
        }
    }
}
