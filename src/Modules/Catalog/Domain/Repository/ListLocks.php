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

    public function lock(string $list): void;
}
