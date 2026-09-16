<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

enum MediaVisibility: string
{
    /** Catalog and content images, served from the CDN. */
    case Public = 'PUBLIC';

    /** Company documents and bank-transfer receipts, served only through expiring links. */
    case Private = 'PRIVATE';
}
