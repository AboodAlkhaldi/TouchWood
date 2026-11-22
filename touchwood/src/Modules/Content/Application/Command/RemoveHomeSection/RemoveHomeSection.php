<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\RemoveHomeSection;

/** **Remove Section…** (content.md §1.4, W1): the section and what it holds go; Add Section brings the type back. */
final readonly class RemoveHomeSection
{
    public function __construct(
        public string $sectionId,
    ) {}
}
