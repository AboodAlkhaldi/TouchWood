<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\Bar;
use Modules\Content\Domain\Model\Footer;

/** A store's announcement bar and footer (content.md §1.5, §5: `content.bars`, `content.footers`), one row each. */
interface FrameItems
{
    /** The store's bar, locked when asked; one never saved is off and empty. */
    public function bar(string $storeId, bool $lock = false): Bar;

    public function saveBar(Bar $bar): void;

    /** The store's footer, locked when asked; one never saved is empty. */
    public function footer(string $storeId, bool $lock = false): Footer;

    public function saveFooter(Footer $footer): void;
}
