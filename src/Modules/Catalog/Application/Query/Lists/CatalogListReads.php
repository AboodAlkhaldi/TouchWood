<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * **The shared lists as the panel's screens read them** (catalog.md §4.4, S1–S7): rows, not domain
 * models, each list in one query or a fixed few — never one per row (frontend.md §5: 15 queries an
 * admin page). Who may read is the query handlers' answer; this only reads.
 */
interface CatalogListReads
{
    /**
     * Every brand, in the list's order, with its fixed number and how many products carry it.
     *
     * @return list<BrandRow>
     */
    public function brands(): array;

    /**
     * Every category, in the English name's order, each with how many products sit in it itself
     * and its place in one store's menu — the store's own, and the base store's beside it (§1.5,
     * amendment 5(a)).
     *
     * @return list<CategoryRow>
     */
    public function categories(?string $storeId, ?string $baseStoreId): array;

    /**
     * Every attribute, in the list's order, with how many values it has and what locks its job.
     *
     * @return list<AttributeRow>
     */
    public function attributes(): array;

    public function attribute(string $attributeId): ?AttributeRow;

    /**
     * One attribute's values, in their order, each saying whether a variant or a product uses it.
     *
     * @return list<ValueRow>
     */
    public function values(string $attributeId): array;

    /**
     * Every attribute set — the screens' "Variations" — with its attributes in order.
     *
     * @return list<VariationRow>
     */
    public function variations(): array;

    /**
     * @return list<LabelRow>
     */
    public function labels(): array;

    /**
     * @return list<WarrantyRow>
     */
    public function warranties(): array;

    /**
     * @return list<WordPairRow>
     */
    public function wordPairs(): array;

    /**
     * The submitted searches that found nothing since a moment, grouped by the words, the store and
     * the language, the most searched first (§1.11).
     *
     * @return array{0: list<NoResultSearchRow>, 1: bool} the page, and whether another follows
     */
    public function searchesWithNoResults(?string $storeId, string $since, int $page, int $perPage): array;

    /**
     * The products a brand's deactivation reaches — every one carrying it, in any stage (§1.6).
     *
     * @return list<ReachedProductRow>
     */
    public function productsOfBrand(string $brandId): array;

    /**
     * The products a category's deactivation reaches — every one in it or under it, in any stage
     * (§1.5, amendment 4(d)).
     *
     * @return list<ReachedProductRow>
     */
    public function productsUnderCategory(string $categoryId): array;
}
