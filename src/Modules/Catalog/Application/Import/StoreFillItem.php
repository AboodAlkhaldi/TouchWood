<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

/**
 * One item of an admins' store file, as its page keeps it (catalog.md §1.3, §5.5; amendment 6(g)):
 * the code and price as the file wrote them, the stock if it gave one, and what the admin did with it.
 * Whether an open item is ready, not ready, archived, already on or an unknown code is read when the
 * page is, since it changes with the product.
 */
final readonly class StoreFillItem
{
    public const string OPEN = 'OPEN';

    public const string ON = 'ON';

    public const string REMOVED = 'REMOVED';

    public function __construct(
        public string $id,
        public int $number,
        public string $code,
        public string $price,
        public ?int $stock,
        public string $state,
    ) {}

    public function with(string $code, string $state): self
    {
        return new self($this->id, $this->number, $code, $this->price, $this->stock, $state);
    }
}
