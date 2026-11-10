<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ShowHomeSection;

/** The section's **Show** switch (content.md §1.4, W1), saved as flipped. */
final readonly class ShowHomeSection
{
    public function __construct(
        public string $sectionId,
        public bool $visible,
    ) {}
}
