<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\RevokeTrustedBrowser;

/**
 * A browser is no longer trusted, so it asks for an SMS code again next time (spec §1.8).
 *
 * A null id means **all of them**: the screen offers both, and the difference is one word in the
 * button rather than a second use case.
 */
final readonly class RevokeTrustedBrowser
{
    public function __construct(
        public ?string $browserId = null,
    ) {}
}
