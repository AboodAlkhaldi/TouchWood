<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Access\Public\Dto\CustomerDto;
use Modules\Sales\Domain\Exception\QuoteChanged;

/** Who the order is snapshotted for: the customer as they are at placing, or the try's copy at Pay (§9.5 #4). */
final readonly class OrderContact
{
    public function __construct(
        public string $name,
        public string $email,
        public string $phone,
        public string $locale,
    ) {}

    /** @throws QuoteChanged with no phone to deliver to */
    public static function of(CustomerDto $customer): self
    {
        return new self(trim($customer->firstName.' '.$customer->lastName), $customer->email, $customer->phone ?? throw new QuoteChanged('phone'), $customer->locale);
    }

    /** @return array{name: string, email: string, phone: string, locale: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'locale' => $this->locale];
    }

    /** @param  array<string, mixed>  $copy  as `toArray` wrote it */
    public static function fromArray(array $copy): self
    {
        /** @var array{name: string, email: string, phone: string, locale: string} $copy */
        return new self($copy['name'], $copy['email'], $copy['phone'], $copy['locale']);
    }
}
