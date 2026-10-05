<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use JsonException;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\ValueObject\ProductCode;

/**
 * **A store file, read and checked** (catalog.md §1.3, amendment 6(g); the guide, §2): codes with
 * their prices, stock optional, at most 1,000 items, each code once. Every problem collected, then
 * the file refused whole. Whether each code is in the catalog is its page's question, never a
 * refusal: an unknown code is corrected or removed there.
 */
final readonly class StoreFillFile
{
    public const string FORMAT = 'touchwood-store-fill/1';

    public const int MAX_ITEMS = 1000;

    public const int MAX_BYTES = 2 * 1024 * 1024;

    /**
     * @param  list<FileItem>  $items
     */
    private function __construct(
        public array $items,
    ) {}

    /**
     * @throws ImportRefused
     */
    public static function read(string $json): self
    {
        $problems = new FileProblems;

        if (strlen($json) > self::MAX_BYTES) {
            $problems->add('file', 'at most 2 MB');
            $problems->refuseIfAny();
        }

        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $error) {
            $problems->add('file', 'valid JSON ('.$error->getMessage().')');
            $problems->refuseIfAny();

            return new self([]);
        }

        if (! is_array($data) || array_is_list($data)) {
            $problems->add('file', 'an object holding "format" and "items"');
            $problems->refuseIfAny();

            return new self([]);
        }

        foreach (array_keys($data) as $key) {
            if (! in_array($key, ['format', 'items'], true)) {
                $problems->add("file › {$key}", 'not a field of the format');
            }
        }

        if (($data['format'] ?? null) !== self::FORMAT) {
            $problems->add('format', 'exactly "'.self::FORMAT.'"');
        }

        $items = $data['items'] ?? null;

        if (! is_array($items) || ! array_is_list($items) || $items === [] || count($items) > self::MAX_ITEMS) {
            $problems->add('items', 'a list of 1 to '.number_format(self::MAX_ITEMS).' items');
            $problems->refuseIfAny();

            return new self([]);
        }

        $read = [];
        /** @var array<string, int> $seen code => the item that named it first */
        $seen = [];

        foreach ($items as $index => $raw) {
            $number = $index + 1;
            $at = "item {$number}";

            if (! is_array($raw) || array_is_list($raw) && $raw !== []) {
                $problems->add($at, 'an object with "code", "price" and, if known, "stock"');

                continue;
            }

            foreach (array_keys($raw) as $key) {
                if (! in_array($key, ['code', 'price', 'stock'], true)) {
                    $problems->add("{$at} › {$key}", 'not a field of the format');
                }
            }

            $code = null;

            try {
                $code = is_string($raw['code'] ?? null) ? ProductCode::of($raw['code'])->value : throw new InvalidCatalogAttribute('code', 'the code as text: 1 to 10 digits, in quotes');
            } catch (InvalidCatalogAttribute $error) {
                $problems->add("{$at} › code", $error->reason);
            }

            if ($code !== null && isset($seen[$code])) {
                $problems->add("{$at} › code", "{$code} again: item {$seen[$code]} names it already");
            }

            $price = ProductsFile::price($raw['price'] ?? null, "{$at} › price", true, $problems);
            $stock = ProductsFile::stock($raw['stock'] ?? null, "{$at} › stock", $problems);

            if ($code !== null && ! isset($seen[$code])) {
                $seen[$code] = $number;

                if ($price !== null) {
                    $read[] = new FileItem($number, $code, $price, $stock);
                }
            }
        }

        $problems->refuseIfAny();

        return new self($read);
    }
}
