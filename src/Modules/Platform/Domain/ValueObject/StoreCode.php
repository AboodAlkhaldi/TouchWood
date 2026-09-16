<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\ValueObject;

use Modules\Platform\Domain\Exception\InvalidStoreAttribute;

/**
 * The store's URL segment: brand.com/{code}.
 *
 * A code must be reachable, so it can never be one of the top-level paths the application keeps
 * for itself: a store named "admin" would be created and then answer 404 forever.
 */
final readonly class StoreCode
{
    /**
     * Top-level paths that are never a store.
     */
    public const array RESERVED = ['up', 'admin', 'api', 'build', 'storage'];

    /**
     * The route requirement for {store}: 2 to 8 lowercase letters that are not a reserved path.
     * The lookahead stops at the end of the segment, so /admin/login is excluded as well as /admin.
     */
    public const string ROUTE_PATTERN = '(?!(?:up|admin|api|build|storage)(?![a-z]))[a-z]{2,8}';

    private function __construct(
        public string $value,
    ) {}

    public static function fromString(string $value): self
    {
        if (preg_match('/^[a-z]{2,8}\z/', $value) !== 1) {
            throw new InvalidStoreAttribute('code', 'expected 2 to 8 lowercase letters');
        }

        if (in_array($value, self::RESERVED, true)) {
            throw new InvalidStoreAttribute('code', "\"{$value}\" is reserved for the application");
        }

        return new self($value);
    }
}
