<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\DownloadCompanyDocument;

/**
 * Staff opening one of a company's papers, or a file it sent to answer a request (b2b.md §3.2).
 */
final readonly class DownloadCompanyDocument
{
    public function __construct(
        public string $companyId,
        public string $mediaId,
    ) {}
}
