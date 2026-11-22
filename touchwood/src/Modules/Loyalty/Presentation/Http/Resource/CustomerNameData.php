<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Resource;

use Modules\Loyalty\Application\Query\CustomerName;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Who a row's points belong to (loyalty.md §4.1): the name, the email only for a customer whose page
 * the reader may open (§9.5 P1); `deleted` for an anonymized customer, shown with no name and no link.
 */
#[TypeScript]
final class CustomerNameData extends Data
{
    public function __construct(
        public string $id,
        public ?string $name,
        public ?string $email,
        public bool $deleted,
    ) {}

    public static function of(CustomerName $customer): self
    {
        return new self($customer->customerId, $customer->name, $customer->email, $customer->deleted);
    }
}
