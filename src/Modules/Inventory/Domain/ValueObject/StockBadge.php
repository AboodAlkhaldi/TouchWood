<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\ValueObject;

/**
 * **The one badge a size's row shows** (inventory.md §4.3.1, Q17, Q18), the first that applies:
 *
 * 1. **Short** (red) - orders hold more than there is: held above in stock (in a wired store, above in
 *    stock plus its returned extra);
 * 2. **Out of Stock** (red) - nothing available on a size that counts on stock;
 * 3. **Orderable** (gray) - a wired ordinary size at 0: it stays orderable (§1.3);
 * 4. **Low** (amber) - available at or below the threshold.
 */
enum StockBadge: string
{
    case Short = 'SHORT';
    case OutOfStock = 'OUT_OF_STOCK';
    case Orderable = 'ORDERABLE';
    case Low = 'LOW';
}
