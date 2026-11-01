<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What each of Ops' forms may offer the reader (ops.md §4): the stores of each, by the permission it asks,
 * and the report kinds they may open. An empty list: that form is not theirs.
 */
#[TypeScript]
final class OpsChoicesData extends Data
{
    /**
     * @param  list<StoreChoiceData>  $notices
     * @param  list<StoreChoiceData>  $messagesToOne
     * @param  list<StoreChoiceData>  $messagesToMany
     * @param  list<StoreChoiceData>  $subscribers
     * @param  list<StoreChoiceData>  $dailyReports
     * @param  list<ReportChoiceData>  $reports  only the kinds this reader may open
     */
    public function __construct(
        public array $notices,
        public array $messagesToOne,
        public array $messagesToMany,
        public array $subscribers,
        public array $dailyReports,
        /** Whether the daily report and its email may be every store's: a super admin's alone. */
        public bool $dailyReportsAllStores,
        public array $reports,
    ) {}
}
