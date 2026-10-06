<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Enums;

/**
 * Where a product is in its life, one stage for every store (catalog.md §1.1, §4.1). Each store's
 * own on/off is its choice of the product's variants (§1.3), not this.
 */
enum ProductStage: string
{
    /** Being written: shown nowhere, chosen by no store; the only stage a product is deleted in. */
    case Draft = 'DRAFT';

    /** Every readiness rule met: stores may choose it. */
    case Ready = 'READY';

    /** Retired: Inactive in every store, restorable. */
    case Archived = 'ARCHIVED';
}
