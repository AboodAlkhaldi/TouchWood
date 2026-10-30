<?php

declare(strict_types=1);

namespace Modules\Ops\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A report's days Ops will not read (ops.md §1.10, §7): a year before 2020 or after next year, more than
 * five years at once, or more than twelve months of searches - the search log keeps no more. The rule
 * names which, so the reports page says it in words.
 */
final class ReportRangeRefused extends OpsError
{
    public const string YEARS = 'years';

    public const string TOO_LONG = 'too_long';

    public const string SEARCHES_TOO_LONG = 'searches_too_long';

    /** @param self::YEARS|self::TOO_LONG|self::SEARCHES_TOO_LONG $rule */
    public function __construct(public readonly string $attribute, public readonly string $rule)
    {
        parent::__construct("Report range refused ({$rule}) at {$attribute}");
    }

    public function type(): string
    {
        return 'ops.report_range_refused';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return ['attribute' => $this->attribute, 'rule' => $this->rule];
    }
}
