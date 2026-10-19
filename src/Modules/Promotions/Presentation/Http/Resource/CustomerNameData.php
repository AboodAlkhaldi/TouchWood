<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Resource;

use Modules\Promotions\Application\Query\CustomerName;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A customer on a Marketing screen - the name only (promotions.md §1.5, answer 17); `deleted` for an
 * anonymized customer, shown as "Deleted customer" with nothing else (owner's Q18).
 */
#[TypeScript]
final class CustomerNameData extends Data
{
    public function __construct(
        public string $id,
        public ?string $name,
        public bool $deleted,
    ) {}

    public static function of(CustomerName $customer): self
    {
        return $customer->anonymized
            ? new self($customer->id, null, true)
            : new self($customer->id, trim($customer->firstName.' '.$customer->lastName), false);
    }
}
