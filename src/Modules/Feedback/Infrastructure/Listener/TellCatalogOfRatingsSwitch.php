<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Listener;

use Modules\Feedback\Application\Review\RatingsSwitch;
use Modules\Platform\Public\Events\SettingChanged;

/**
 * Platform saved a setting (feedback.md §6): Feedback's ratings switch reaches Catalog's lists. Run at
 * once, as Inventory's and Pricing's settings listeners, so a list never shows what the switch no
 * longer says.
 */
final readonly class TellCatalogOfRatingsSwitch
{
    public function __construct(
        private RatingsSwitch $switch,
    ) {}

    public function handle(SettingChanged $event): void
    {
        $this->switch->changed($event->key, $event->storeId);
    }
}
