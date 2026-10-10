<?php

declare(strict_types=1);

namespace Modules\Catalog\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * The permissions Catalog's use cases check (catalog.md §3). Declared into Access's catalog at boot.
 *
 * **One permission per job**, and any role — staff or admin — may be given any of them (owner,
 * 2026-10-02), **except filling a store from a file, for admin roles only** (owner, 2026-10-05,
 * amendment 6(h): "only admins, and super too"). Every job is per store: a store's own row is checked in that
 * store; a product's shared data in every store where it is Active; and a shared list — the tree,
 * the brands, the attributes, the labels, the warranties, the word pairs — reaches every store, so
 * its one permission is checked with All stores (`PermissionScope::allStores()`). The import and
 * the system's jobs are reserved: a Super Admin, or the system, and never offered in a role.
 *
 * Each permission's handlers arrive with their build step; the module's README says which.
 */
final class CatalogPermissions
{
    /** Create a product: a draft, Active nowhere - the job held in some store that is on (amendment 13(f)). */
    public const string PRODUCT_CREATE = 'catalog.product.create';

    /** Change a product's shared data, its variants, gallery, relations and search words. */
    public const string PRODUCT_UPDATE = 'catalog.product.update';

    /** Correct a mistyped product code — its own job, since the code is what the provider matches. */
    public const string VARIANT_CORRECT_CODE = 'catalog.variant.correct_code';

    /** Make a product ready to be shown. */
    public const string PRODUCT_PUBLISH = 'catalog.product.publish';

    /** Archive a product and restore it; delete a draft. */
    public const string PRODUCT_ARCHIVE = 'catalog.product.archive';

    /** List products and view one, every store's row shown for the stores the reader covers. */
    public const string PRODUCT_VIEW = 'catalog.product.view';

    /** Choose what the store sells: a whole product or single variants, Active or Inactive. */
    public const string LISTING_CHOOSE = 'catalog.listing.choose';

    /** Each variant's retail and wholesale switches; the product's minimum and maximum per mode. */
    public const string LISTING_SELLING = 'catalog.listing.selling';

    /** Mark a product or a variant "Not available now" in the store, and clear it. */
    public const string LISTING_UNAVAILABLE = 'catalog.listing.unavailable';

    /** Attach the store's labels to a product. */
    public const string LISTING_LABELS = 'catalog.listing.labels';

    /** Order the categories in the store's menu. */
    public const string CATEGORY_RANK = 'catalog.category.rank';

    /** The category tree: add, rename, move, deactivate with each product's fate, activate, delete. */
    public const string CATEGORY_MANAGE = 'catalog.category.manage';

    /** Brands: add, edit, make default, deactivate with each product's fate, activate, delete. */
    public const string BRAND_MANAGE = 'catalog.brand.manage';

    /** Attributes, their values and colours, and attribute sets. */
    public const string ATTRIBUTE_MANAGE = 'catalog.attribute.manage';

    /** The list of labels stores attach to products. */
    public const string LABEL_MANAGE = 'catalog.label.manage';

    /** The list of warranties a product may carry. */
    public const string WARRANTY_MANAGE = 'catalog.warranty.manage';

    /** The shared search word pairs, and reading the zero-result list that feeds them. */
    public const string SEARCH_WORD_MANAGE = 'catalog.search_word.manage';

    /** Preview and run the JSON import — Super Admin only (handoff §9.1). */
    public const string IMPORT_RUN = 'catalog.import.run';

    /** The admins' store file: switching existing products on in a store from a file (§1.3, amendment 6(g)). Admin roles only (6(h)). */
    public const string LISTING_FILL = 'catalog.listing.fill';

    /** The repair job that rebuilds the listing read model — the system's. */
    public const string LISTING_REBUILD = 'catalog.listing.rebuild';

    /** The nightly removal of search log entries older than twelve months — the system's. */
    public const string SEARCH_LOG_PRUNE = 'catalog.search_log.prune';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $jobs = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, kind: PermissionKind::PerStore, group: PermissionGroup::Catalog, adminOnly: in_array($name, self::adminOnly(), true)),
            self::jobs(),
        );

        // Reserved and store-free: the import reaches every store at once, and a job belongs to none.
        $reserved = array_map(
            static fn (string $name): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
            self::reserved(),
        );

        return [...$jobs, ...$reserved];
    }

    /**
     * @return list<string> the eighteen jobs a role may hold (§3)
     */
    public static function jobs(): array
    {
        return [
            self::PRODUCT_CREATE,
            self::PRODUCT_UPDATE,
            self::VARIANT_CORRECT_CODE,
            self::PRODUCT_PUBLISH,
            self::PRODUCT_ARCHIVE,
            self::PRODUCT_VIEW,
            self::LISTING_CHOOSE,
            self::LISTING_SELLING,
            self::LISTING_UNAVAILABLE,
            self::LISTING_LABELS,
            self::CATEGORY_RANK,
            self::CATEGORY_MANAGE,
            self::BRAND_MANAGE,
            self::ATTRIBUTE_MANAGE,
            self::LABEL_MANAGE,
            self::WARRANTY_MANAGE,
            self::SEARCH_WORD_MANAGE,
            self::LISTING_FILL,
        ];
    }

    /**
     * @return list<string> the jobs only an admin role may hold (amendment 6(h))
     */
    public static function adminOnly(): array
    {
        return [self::LISTING_FILL];
    }

    /**
     * @return list<string> the six jobs over a shared list, checked with All stores
     */
    public static function sharedLists(): array
    {
        return [
            self::CATEGORY_MANAGE,
            self::BRAND_MANAGE,
            self::ATTRIBUTE_MANAGE,
            self::LABEL_MANAGE,
            self::WARRANTY_MANAGE,
            self::SEARCH_WORD_MANAGE,
        ];
    }

    /**
     * @return list<string> Super Admin and the system only, never in a role
     */
    public static function reserved(): array
    {
        return [
            self::IMPORT_RUN,
            self::LISTING_REBUILD,
            self::SEARCH_LOG_PRUNE,
        ];
    }
}
