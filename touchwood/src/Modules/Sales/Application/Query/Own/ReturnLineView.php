<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

/** A line of a return (sales.md §1.8): the order line's snapshot, the pieces asked and — once received — those that came. */
final readonly class ReturnLineView
{
    /**
     * @param  list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>  $values
     */
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public array $values,
        public string $code,
        public bool $gift,
        public int $asked,
        public ?int $received,
    ) {}
}
