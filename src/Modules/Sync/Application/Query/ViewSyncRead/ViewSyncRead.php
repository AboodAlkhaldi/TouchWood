<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Query\ViewSyncRead;

/**
 * One read's page (sync.md §4.1 Y2, §9.3 #19): its header, and one of its sections - Prices Changed,
 * Stock Changed, Reported, Codes Asked - a page of 100 at a time, each section paged on its own (Q32).
 */
final readonly class ViewSyncRead
{
    public const string PRICES = 'PRICES';

    public const string STOCK = 'STOCK';

    public const string REPORTED = 'REPORTED';

    public const string CODES = 'CODES';

    /** @var list<string> */
    public const array SECTIONS = [self::PRICES, self::STOCK, self::REPORTED, self::CODES];

    public function __construct(
        public string $readId,
        public string $section = self::PRICES,
        public int $page = 1,
    ) {}
}
