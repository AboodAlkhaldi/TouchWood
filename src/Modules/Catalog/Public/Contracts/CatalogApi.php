<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use DateTimeImmutable;
use Modules\Catalog\Public\Dto\BrandDto;
use Modules\Catalog\Public\Dto\CategoryDto;
use Modules\Catalog\Public\Dto\CategoryPathDto;
use Modules\Catalog\Public\Dto\FoundProductsDto;
use Modules\Catalog\Public\Dto\FoundVariantsDto;
use Modules\Catalog\Public\Dto\LineFactsDto;
use Modules\Catalog\Public\Dto\ProductCardDto;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\SearchSummaryDto;
use Modules\Catalog\Public\Dto\StoreVariantDto;
use Modules\Catalog\Public\Dto\VariantBriefDto;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Dto\WholesaleTermsDto;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * What other modules may ask Catalog (catalog.md §2.1): ids in, DTOs out. Module to module, so no
 * permission is checked here — the calling use case checks its own. Plain reads: no lock, no
 * transaction.
 */
interface CatalogApi
{
    /**
     * For Pricing, Inventory, Sales, Shipping: the code, the product, the values, weight and
     * dimensions. **The code is for staff and the modules above — never shown to a shopper**, an
     * order's own pages included (owner, 2026-10-04, amendment 5(d)).
     */
    public function variant(string $variantId): ?VariantDto;

    /**
     * **The one variant carrying the code**, archived or not — every variant has its own (amendment
     * 16(a), replacing 3(e)'s `variantsByCode`) — for Sync (the provider's codes), Pricing and the
     * import's later sections. A code that is not one, or one no variant carries now, finds none.
     */
    public function variantByCode(string $code): ?VariantDto;

    /**
     * **The variants carrying these codes, in one read** (amendment 21): for Sync, whose reads name
     * thousands of the provider's codes at once. Keyed by each code as given - read as `variantByCode`
     * reads one (spaces around trimmed, Arabic-Indic digits as Latin) - archived ones included; a code
     * that is not one, or one no variant carries, is left out. A few queries however many.
     *
     * @param  list<string>  $codes
     * @return array<string, VariantDto>
     */
    public function variantsByCodes(array $codes): array;

    /**
     * For Pricing and Inventory: **the product's variants in its own order** — archived ones only
     * when asked (catalog.md §2.1, amendment 16(e)). An unknown product has none.
     *
     * @return list<string>
     */
    public function variantIdsOf(string $productId, bool $includeArchived = false): array;

    /**
     * **Many products' variants in one read** (stage 5's screens step 2; pricing.md §4.3.3): each product's
     * variants in its own order, archived ones only when asked, keyed by product id - a product with none,
     * or unknown, left out. One query however many products.
     *
     * @param  list<string>  $productIds
     * @return array<string, list<string>>
     */
    public function variantIdsOfProducts(array $productIds, bool $includeArchived = false): array;

    /**
     * For Pricing's category discounts: **the products in the category and in every category below
     * it**, any stage (amendment 16(e)). An unknown category holds none. When a category moves,
     * `CategoryMoved` says so.
     *
     * @return list<string>
     */
    public function productIdsInCategory(string $categoryId): array;

    /**
     * For Pricing's category discounts: **the category, and whether it is switched on** (amendment 17;
     * owner, 2026-10-13) - a discount on one that does not exist or is off is refused. Null for an
     * unknown category.
     */
    public function category(string $categoryId): ?CategoryDto;

    /**
     * For Pricing's "Needs a Price" and Inventory: **the variants switched on in the store** — Active
     * there (§1.3) — in no particular order (amendment 16(e)).
     *
     * @return list<string>
     */
    public function switchedOnVariantIds(StoreId $store): array;

    /**
     * **Many variants in one read**, keyed by id, an unknown id left out (amendment 16(i)): a few
     * queries however many, so a page of Pricing's or Inventory's keeps its budget.
     *
     * @param  list<string>  $variantIds
     * @return array<string, VariantDto>
     */
    public function variants(array $variantIds): array;

    /**
     * **Many sizes in brief, in one query** (amendment 23(h), stage 5's screens step 4): each size's product,
     * the product's names and stage, the size's place and whether it is archived - keyed by id, an unknown id
     * left out. For Inventory's Low Stock list (archived sizes left off, the rest ordered by name and place)
     * and its changes' checks, where `variants()` and `products()` would cost three queries.
     *
     * @param  list<string>  $variantIds
     * @return array<string, VariantBriefDto>
     */
    public function variantBriefs(array $variantIds): array;

    /**
     * **Many products in one read**, keyed by id, an unknown id left out (amendment 16(i)).
     *
     * @param  list<string>  $productIds
     * @return array<string, ProductDto>
     */
    public function products(array $productIds): array;

    /**
     * For Promotions, once per cart (amendment 18; promotions.md §1.2): **each variant's product,
     * brand, and the product's category with every one above it**, nearest first, in one query —
     * keyed by variant id, an unknown id left out. Archived variants and products in any stage
     * answer too: what a line may take is the coupon's question, not this read's.
     *
     * @param  list<string>  $variantIds
     * @return array<string, LineFactsDto>
     */
    public function lineFacts(array $variantIds): array;

    /**
     * For Promotions — a coupon's include and exclude lists (amendment 20; promotions.md §1.2): **the
     * brand and whether it is switched on**, so a list naming one that does not exist or is off is
     * refused; null for an unknown one. As `category()` for Pricing (amendment 17).
     */
    public function brand(string $brandId): ?BrandDto;

    /**
     * For Promotions — a coupon's lists checked and named (amendment 27; promotions.md §1.2): **many
     * brands in one read**, each with whether it is switched on, keyed by id, an unknown id left out.
     *
     * @param  list<string>  $brandIds
     * @return array<string, BrandDto>
     */
    public function brands(array $brandIds): array;

    /**
     * **Every brand switched on** (amendment 28, Promotions' screens): the choices of a coupon's rule
     * picker, in the brands' own order (position, then name), one query.
     *
     * @return list<BrandDto>
     */
    public function activeBrands(): array;

    /**
     * For Feedback — reviews, questions and the wishlist (amendment 24; feedback.md §9.1b, 19): **which of
     * these products the store sells now**, in one read — a ready product, not hidden with its category
     * or brand, not "Not available now" there, with at least one variant switched on there that is not
     * archived nor itself "Not available now". Stock does not count. Answered as lower-case ids, in no
     * particular order; an unknown id, or one the store does not sell, left out.
     *
     * @param  list<string>  $productIds
     * @return list<string>
     */
    public function productsSoldIn(StoreId $store, array $productIds): array;

    /**
     * For Feedback's pages — a wishlist, "My reviews", the staff queues (amendment 26(a)): **these
     * products as cards, whatever became of them**, in two reads however many: each one's name in the
     * language (the other one where it has none in it), its current address in it where it has one,
     * its first photo, its stage, and whether the store sells it now (`productsSoldIn`'s rule). Keyed
     * by lower-case id; an unknown id left out.
     *
     * @param  'ar'|'en'  $locale
     * @param  list<string>  $productIds
     * @return array<string, ProductCardDto>
     */
    public function productCards(StoreId $store, string $locale, array $productIds): array;

    /**
     * For Sales — a cart, a checkout, an edit (amendment 29(a); sales.md §2.3): **many sizes in a store
     * in one query**, each answered exactly as `storeVariant` answers it. Keyed by lower-case id; an
     * unknown id left out.
     *
     * @param  list<string>  $variantIds
     * @return array<string, StoreVariantDto>
     */
    public function storeVariants(StoreId $store, array $variantIds): array;

    /**
     * For Sales (amendment 29(b); sales.md §1.1, answer 22): **which of these products the store's
     * category switches leave open to this shopper** — an individual, the categories switched on for
     * individuals; a company, those for companies; a guest, either (amendment 14(a), P25). A product's
     * own category decides; one with no category, or a category with no switches set in the store, is
     * open to all. **The category switches only**: an individual buys retail only, which each line's
     * `saleModes` (from `storeVariants`) tells. In one query; lower-case ids in no particular order, an
     * unknown id left out.
     *
     * @param  list<string>  $productIds
     * @return list<string>
     */
    public function productsOpenTo(StoreId $store, ShopperKind $viewer, array $productIds): array;

    /** For Sales's record of an order, Feedback, Content. */
    public function product(string $productId): ?ProductDto;

    /**
     * For Sales: the variant in that store (§1.3). Null for a variant that does not exist. Whether
     * the store is on is Platform's to say (`PlatformApi::store`); a store that is off still answers
     * here, as it is prepared before it opens (amendment 4(e)).
     */
    public function storeVariant(StoreId $store, string $variantId): ?StoreVariantDto;

    /**
     * The variant a shopper's picked values name (handoff §9.1: the server resolves it, never the
     * browser): the product's variant, not archived, made of exactly these values.
     *
     * @param  list<string>  $valueIds
     */
    public function resolveVariant(string $productId, array $valueIds): ?string;

    /**
     * **A store's sizes for a store-wide list** (amendment 23(a); pricing.md §4.3.3, inventory.md §4.3):
     * the variants switched on in the store - with `anyReady`, every ready product's variants not
     * archived; with `only`, **exactly the variants named**, whatever their state (a size priced or
     * stocked there though switched off) - less those in `except`; with `soldWholesale`, only those the
     * store sells wholesale. Found as the products list finds: a name in either language, or a size's
     * code. **Ordered by product name in the page's language, then the size's place**, keyset-paged by
     * `after` (the `next` of the page before; one that is not one starts at the top), `limit` from 1 to
     * 100, with the total. So a filter owned by the module asking - its sizes, as `only` or `except` -
     * pages by Catalog's order. With `notArchived`, a size archived - or of an archived product - is left
     * out (Pricing's Needs a Price, stage 5's screens step 2). A fixed two queries however many sizes or ids;
     * one with `withTotal` false, for a caller that knows the total already - the total is then 0 (amendment
     * 23(h), stage 5's screens step 4).
     *
     * @param  list<string>|null  $only
     * @param  list<string>|null  $except
     */
    public function searchVariants(StoreId $store, ?string $text, ?array $only = null, ?array $except = null, bool $soldWholesale = false, bool $anyReady = false, ?string $after = null, int $limit = 50, bool $notArchived = false, bool $withTotal = true): FoundVariantsDto;

    /**
     * **Products for a picker** (amendment 23(b)): with `readySwitchedOn`, the store's ready products
     * with a size switched on there (pricing.md Q13); with `only`, among the products named (P2's "the
     * products with sales here"); with neither, every product. Found by a name in either language or a
     * code, by name in the page's language, keyset-paged with the total, as `searchVariants`. A fixed two
     * queries.
     *
     * @param  list<string>|null  $only
     */
    public function searchProducts(StoreId $store, ?string $text, bool $readySwitchedOn, ?array $only = null, ?string $after = null, int $limit = 50): FoundProductsDto;

    /**
     * **Categories with the path to each** (amendment 23(c)), keyed by id in the order asked - an
     * unknown id left out - in one query.
     *
     * @param  list<string>  $ids
     * @return array<string, CategoryPathDto>
     */
    public function categoriesWithPaths(array $ids): array;

    /**
     * **Every category switched on, with the path to each** (amendment 23(f), stage 5's screens step 3):
     * the choices of Pricing's category discount dialog - a discount on a category that is off is refused.
     * Two queries.
     *
     * @return list<CategoryPathDto>
     */
    public function activeCategories(): array;

    /**
     * **Each category's products, and those of every category below it** (amendment 23, stage 5's screens
     * step 3's review): `productIdsInCategory` for many categories at once - a discount preview's other
     * discounts - in one query, keyed by category in the order asked, each list in id order; an unknown
     * category holds none.
     *
     * @param  list<string>  $categoryIds
     * @return array<string, list<string>>
     */
    public function productIdsInCategories(array $categoryIds): array;

    /**
     * **A category's products, and those of every category below it** (amendment 23(c)), any stage, by
     * name in the page's language, found and paged as `searchProducts` - for a discount's "Left Out". An
     * unknown category holds none. A fixed two queries.
     */
    public function productsInCategory(string $categoryId, ?string $text, ?string $after = null, int $limit = 50): FoundProductsDto;

    /**
     * **Each size's wholesale terms in the store** (amendment 23(d)): its product's wholesale minimum and
     * maximum there, and whether it sells wholesale there - keyed by variant id, an unknown id left out.
     * One query however many.
     *
     * @param  list<string>  $variantIds
     * @return array<string, WholesaleTermsDto>
     */
    public function wholesaleOf(StoreId $store, array $variantIds): array;

    /**
     * **The searches by phrase, for Ops' reports** (amendment 37; ops.md §1.10, §1.11, §2.3): from `from` up to
     * `to`, not included, one store's or every store's; each phrase as the log keeps it (normalised), in a
     * store and a language, how many times and how many found nothing; most searched first, keyset-paged,
     * `limit` 1 to 1000. The log keeps 12 months (§1.11): nothing older is ever there. **No person** - the log
     * has none. One query; reads only, for a caller that has already asked who may see it.
     */
    public function searchSummary(?StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to, ?string $after = null, int $limit = 100): SearchSummaryDto;
}
