<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Command\UploadCarrierLogo;

/**
 * A carrier's logo, uploaded from its form before the form is saved (shipping.md §1.3), as a brand's
 * logo is (catalog.md §4.4, P5).
 */
final readonly class UploadCarrierLogo
{
    public function __construct(
        public string $storeId,
        public string $path,
        public string $fileName,
    ) {}
}
