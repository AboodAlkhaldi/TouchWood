<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

/**
 * One lock per shared list, held to the end of the change's transaction (catalog.md §5): every
 * question about the other rows — is this name or slug taken, which brand is the default, which
 * category is below which — is asked and answered under it, so two people changing the same list at
 * once queue up instead of both winning (lessons 37, 64). A list's lock is taken before anything of
 * it is read.
 */
interface ListLocks
{
    public const string BRANDS = 'brands';

    public const string CATEGORIES = 'categories';

    /** Attributes, their values and the attribute sets: one lock, since each reads the others. */
    public const string ATTRIBUTES = 'attributes';

    public const string LABELS = 'labels';

    public const string WARRANTIES = 'warranties';

    public const string WORD_PAIRS = 'word_pairs';

    /**
     * Every product change: codes and slugs are decided across products under it, and the listing's
     * rows written. Taken after no list's lock — a product change reads the list rows it points at
     * with a row lock instead, and a list change that changes products or their rows takes this one
     * before its own — so the two never wait on each other in a circle.
     */
    public const string PRODUCTS = 'products';

    public function lock(string $list): void;
}
