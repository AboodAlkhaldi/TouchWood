<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Daily Reports (ops.md §4.15 O9): the kept reports, the reader's own emails, the stores Make may offer, the stores an
 * email row may be shown for (those, and any switched off under an email the reader set up), and each store's time zone
 * by its id, `''` being the base store's, which every-store reports and emails are read in.
 */
#[TypeScript]
final class DailyReportsScreenData extends Data
{
    /**
     * @param  list<DailyReportData>  $reports
     * @param  list<DailyReportEmailData>  $emails
     * @param  list<StoreChoiceData>  $stores
     * @param  list<StoreChoiceData>  $emailStores
     * @param  array<string, string>  $zones
     */
    public function __construct(
        public array $reports,
        public ?string $next,
        public array $emails,
        public array $stores,
        public array $emailStores,
        public bool $allStores,
        public array $zones,
    ) {}
}
