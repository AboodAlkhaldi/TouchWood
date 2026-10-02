<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query\ViewTypeLists;

use InvalidArgumentException;

/**
 * One store's list of one kind, as staff manage it (b2b.md §1.3, §4.6): its company types or its
 * document types, active or not.
 */
final readonly class ViewTypeLists
{
    public const string COMPANY = 'company';

    public const string DOCUMENT = 'document';

    /**
     * @param  string|null  $storeId  the store the panel is working in; null when the reader has none
     */
    public function __construct(
        public ?string $storeId,
        public string $kind,
    ) {
        if ($kind !== self::COMPANY && $kind !== self::DOCUMENT) {
            throw new InvalidArgumentException("A type list is \"company\" or \"document\", not \"{$kind}\".");
        }
    }
}
