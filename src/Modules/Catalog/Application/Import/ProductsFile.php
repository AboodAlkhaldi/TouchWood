<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use JsonException;
use Modules\Catalog\Application\Products\ProductParts;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\TooMany;
use Modules\Catalog\Domain\Model\Product;
use Modules\Catalog\Domain\Service\ArabicText;
use Modules\Catalog\Domain\ValueObject\CatalogText;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Domain\ValueObject\ProductName;
use Modules\Catalog\Domain\ValueObject\SearchWords;
use Modules\Catalog\Domain\ValueObject\Slug;
use Modules\Catalog\Domain\ValueObject\StructuredText;
use Modules\Catalog\Domain\ValueObject\VariantDetail;
use Modules\Catalog\Domain\ValueObject\VariantMeasures;

/**
 * **A product file, read and checked** (catalog.md §1.12; the guide in docs/modules/catalog-import/):
 * every rule of the guide's §1.7 — the shape, each field's kind and limits, the rules the panel
 * holds a product to (its names, slugs, description, codes, search words, measures and details,
 * through the same value objects), two products never sharing a code, two variants of one product
 * never alike, every photo in the zip. **Every problem is collected**, then the file is refused
 * whole with all of them (`ImportRefused`). Names of lists are only checked as text here: whether
 * the catalog has them is the import's page's question.
 */
final readonly class ProductsFile
{
    public const string FORMAT = 'touchwood-products/1';

    public const int MAX_PRODUCTS = 2000;

    public const int MAX_BYTES = 20 * 1024 * 1024;

    /** A list's name — a brand, a category, an attribute, a value, a set, a warranty (§5.3). */
    public const int NAME_MAX = 100;

    public const int GALLERY_MAX = 20;

    public const int VARIANT_PHOTOS_MAX = 10;

    private const array PRODUCT_FIELDS = ['name', 'slug', 'description', 'brand', 'category', 'warranty', 'attribute_set', 'variants', 'photos', 'search_words', 'filters', 'related', 'goes_with', 'stores'];

    private const array VARIANT_FIELDS = ['code', 'values', 'details', 'weight_g', 'length_mm', 'width_mm', 'height_mm', 'photos'];

    /**
     * @param  list<FileProduct>  $products
     */
    private function __construct(
        public array $products,
    ) {}

    /**
     * @param  list<string>|null  $zipPaths  the files inside the zip, or null for a JSON uploaded alone
     *
     * @throws ImportRefused
     */
    public static function read(string $json, ?array $zipPaths): self
    {
        $problems = new FileProblems;

        if (strlen($json) > self::MAX_BYTES) {
            $problems->add('file', 'at most 20 MB');
            $problems->refuseIfAny();
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException $error) {
            $problems->add('file', 'valid JSON ('.$error->getMessage().')');
            $problems->refuseIfAny();

            return new self([]);
        }

        if (! is_array($data) || array_is_list($data)) {
            $problems->add('file', 'an object holding "format" and "products"');
            $problems->refuseIfAny();

            return new self([]);
        }

        self::onlyFields($data, ['format', 'products'], 'file', $problems);

        if (($data['format'] ?? null) !== self::FORMAT) {
            $problems->add('format', 'exactly "'.self::FORMAT.'"');
        }

        $products = $data['products'] ?? null;

        if (! is_array($products) || ! array_is_list($products) || $products === [] || count($products) > self::MAX_PRODUCTS) {
            $problems->add('products', 'a list of 1 to '.number_format(self::MAX_PRODUCTS).' products');
            $problems->refuseIfAny();

            return new self([]);
        }

        $zip = $zipPaths === null ? null : array_flip(array_map(self::path(...), $zipPaths));
        $read = [];
        /** @var array<string, list<int>> $owners code => the products holding it */
        $owners = [];

        foreach ($products as $index => $raw) {
            $product = self::product($raw, $index + 1, $zip, $problems);

            if ($product !== null) {
                $read[] = $product;

                foreach ($product->codes() as $code) {
                    $owners[$code][] = $product->number;
                }
            }
        }

        foreach ($owners as $code => $numbers) {
            if (count($numbers) > 1) {
                $problems->add('products '.implode(', ', $numbers), "the code {$code} on two products: two products never share a code");
            }
        }

        $problems->refuseIfAny();

        return new self($read);
    }

    /**
     * A path inside a zip, as one: forward slashes, no leading "./".
     */
    public static function path(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path;
    }

    /**
     * @param  array<string, int>|null  $zip
     */
    private static function product(mixed $raw, int $number, ?array $zip, FileProblems $problems): ?FileProduct
    {
        $at = "product {$number}";

        if (! is_array($raw) || array_is_list($raw) && $raw !== []) {
            $problems->add($at, 'an object');

            return null;
        }

        self::onlyFields($raw, self::PRODUCT_FIELDS, $at, $problems);
        [$nameAr, $nameEn] = self::name($raw['name'] ?? null, "{$at} › name", $problems);
        [$slugAr, $slugEn] = self::slugs($raw['slug'] ?? null, "{$at} › slug", $problems);
        [$descriptionAr, $descriptionEn] = self::descriptions($raw['description'] ?? null, "{$at} › description", $problems);
        $set = self::listName($raw['attribute_set'] ?? null, "{$at} › attribute_set", $problems);
        $variants = self::variants($raw['variants'] ?? null, $set !== null || array_key_exists('attribute_set', $raw), $at, $zip, $problems);
        [$brand, $brandNumber] = self::brand($raw['brand'] ?? null, "{$at} › brand", $problems);

        return new FileProduct(
            $number,
            $nameAr ?? '',
            $nameEn,
            $slugAr,
            $slugEn,
            $descriptionAr,
            $descriptionEn,
            $brand,
            self::category($raw['category'] ?? null, "{$at} › category", $problems),
            self::listName($raw['warranty'] ?? null, "{$at} › warranty", $problems),
            $set,
            $variants,
            self::photos($raw['photos'] ?? null, self::GALLERY_MAX, "{$at} › photos", $zip, $problems),
            self::searchWords($raw['search_words'] ?? null, "{$at} › search_words", $problems),
            self::filters($raw['filters'] ?? null, "{$at} › filters", $problems),
            self::codes($raw['related'] ?? null, "{$at} › related", $problems),
            self::codes($raw['goes_with'] ?? null, "{$at} › goes_with", $problems),
            self::stores($raw['stores'] ?? null, "{$at} › stores", $problems),
            $brandNumber,
        );
    }

    /**
     * The brand's fixed number, or its name (amendment 7(b)).
     *
     * @return array{string|null, int|null} the name, or the number
     */
    private static function brand(mixed $raw, string $at, FileProblems $problems): array
    {
        if (is_int($raw)) {
            if ($raw >= 1) {
                return [null, $raw];
            }

            $problems->add($at, "the brand's number, from 1, or its name");

            return [null, null];
        }

        if ($raw !== null && ! is_string($raw)) {
            $problems->add($at, "the brand's number, from 1, or its name");

            return [null, null];
        }

        return [self::listName($raw, $at, $problems), null];
    }

    /**
     * @param  array<array-key, mixed>  $object
     * @param  list<string>  $fields
     */
    private static function onlyFields(array $object, array $fields, string $at, FileProblems $problems): void
    {
        foreach (array_keys($object) as $key) {
            if (! in_array($key, $fields, true)) {
                $problems->add("{$at} › {$key}", 'not a field of the format');
            }
        }
    }

    /**
     * @return array{string|null, string|null}
     */
    private static function name(mixed $raw, string $at, FileProblems $problems): array
    {
        if (! is_array($raw) || ! is_string($raw['ar'] ?? null)) {
            $problems->add($at, 'an object with the Arabic name, "ar", and the English one, "en", if there is one');

            return [null, null];
        }

        self::onlyFields($raw, ['ar', 'en'], $at, $problems);
        $en = $raw['en'] ?? null;

        if ($en !== null && ! is_string($en)) {
            $problems->add("{$at} › en", 'text');

            return [null, null];
        }

        try {
            $name = ProductName::of($raw['ar'], $en);

            return [$name->ar, $name->en];
        } catch (InvalidCatalogAttribute $error) {
            $problems->add($at, $error->reason);

            return [null, null];
        }
    }

    /**
     * @return array{string|null, string|null}
     */
    private static function slugs(mixed $raw, string $at, FileProblems $problems): array
    {
        if ($raw === null) {
            return [null, null];
        }

        if (! is_array($raw)) {
            $problems->add($at, 'an object with "ar" and "en"');

            return [null, null];
        }

        self::onlyFields($raw, ['ar', 'en'], $at, $problems);
        $slugs = [];

        foreach (['ar', 'en'] as $locale) {
            $value = $raw[$locale] ?? null;

            if ($value === null) {
                $slugs[] = null;

                continue;
            }

            try {
                $slugs[] = is_string($value) ? Slug::of($locale, $value)->value : throw new InvalidCatalogAttribute('slug', 'text');
            } catch (InvalidCatalogAttribute $error) {
                $problems->add("{$at} › {$locale}", $error->reason);
                $slugs[] = null;
            }
        }

        return [$slugs[0], $slugs[1]];
    }

    /**
     * @return array{array{blocks: list<array<string, mixed>>}|null, array{blocks: list<array<string, mixed>>}|null}
     */
    private static function descriptions(mixed $raw, string $at, FileProblems $problems): array
    {
        if ($raw === null) {
            return [null, null];
        }

        if (! is_array($raw)) {
            $problems->add($at, 'an object with "ar" and "en"');

            return [null, null];
        }

        self::onlyFields($raw, ['ar', 'en'], $at, $problems);
        $documents = [];

        foreach (['ar', 'en'] as $locale) {
            $value = $raw[$locale] ?? null;

            if ($value === null) {
                $documents[] = null;

                continue;
            }

            try {
                $document = is_string($value) ? DescriptionText::document($value) : throw new InvalidCatalogAttribute('description', 'text');
                StructuredText::of('description', $document, Product::DESCRIPTION_MAX);
                $documents[] = $document;
            } catch (InvalidCatalogAttribute $error) {
                $problems->add("{$at} › {$locale}", $error->reason);
                $documents[] = null;
            }
        }

        return [$documents[0], $documents[1]];
    }

    private static function listName(mixed $raw, string $at, FileProblems $problems): ?string
    {
        if ($raw === null) {
            return null;
        }

        try {
            return is_string($raw) ? CatalogText::oneLine('name', $raw, self::NAME_MAX) : throw new InvalidCatalogAttribute('name', 'text');
        } catch (InvalidCatalogAttribute $error) {
            $problems->add($at, $error->reason);

            return null;
        }
    }

    /**
     * @return list<string>|null
     */
    private static function category(mixed $raw, string $at, FileProblems $problems): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (! is_string($raw)) {
            $problems->add($at, 'the path of names from the top, " / " between them');

            return null;
        }

        $path = [];

        foreach (explode('/', $raw) as $part) {
            $name = self::listName($part, $at, $problems);

            if ($name === null) {
                return null;
            }

            $path[] = $name;
        }

        return $path;
    }

    /**
     * @param  array<string, int>|null  $zip
     * @return list<FileVariant>
     */
    private static function variants(mixed $raw, bool $hasSet, string $at, ?array $zip, FileProblems $problems): array
    {
        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            $problems->add("{$at} › variants", 'a list of at least one variant');

            return [];
        }

        if (! $hasSet && count($raw) > 1) {
            $problems->add("{$at} › variants", 'one variant, with no values, for a product with no attribute_set');
        }

        $variants = [];
        $combinations = [];
        $attributes = null;

        foreach ($raw as $index => $item) {
            $where = "{$at} › variants ".($index + 1);

            if (! is_array($item) || array_is_list($item) && $item !== []) {
                $problems->add($where, 'an object');

                continue;
            }

            self::onlyFields($item, self::VARIANT_FIELDS, $where, $problems);
            $values = self::values($item['values'] ?? null, "{$where} › values", $problems);

            if ($hasSet && $values === []) {
                $problems->add("{$where} › values", 'one value for each attribute of the product\'s attribute_set');
            }

            if (! $hasSet && $values !== []) {
                $problems->add("{$where} › values", 'values need the product\'s attribute_set');
            }

            $key = self::combination($values);
            $names = array_keys($key);

            if ($attributes !== null && $names !== $attributes) {
                $problems->add("{$where} › values", 'the same attributes as the product\'s first variant');
            }

            $attributes ??= $names;

            if (isset($combinations[serialize($key)])) {
                $problems->add("{$where} › values", 'not the same values as variant '.$combinations[serialize($key)].': two variants of one product are never alike');
            } else {
                $combinations[serialize($key)] = $index + 1;
            }

            $code = null;

            try {
                $code = is_string($item['code'] ?? null) ? ProductCode::of($item['code'])->value : throw new InvalidCatalogAttribute('code', 'the code as text: 1 to 10 digits, in quotes');
            } catch (InvalidCatalogAttribute $error) {
                $problems->add("{$where} › code", $error->reason);
            }

            $measures = [];

            foreach (['weight_g', 'length_mm', 'width_mm', 'height_mm'] as $field) {
                $value = $item[$field] ?? null;

                if ($value !== null && ! is_int($value)) {
                    $problems->add("{$where} › {$field}", 'a whole number');
                    $value = null;
                }

                $measures[] = $value;
            }

            try {
                VariantMeasures::of(...$measures);
            } catch (InvalidCatalogAttribute $error) {
                $problems->add("{$where} › ".($error->attribute === 'weight_grams' ? 'weight_g' : $error->attribute), $error->reason);
            }

            if ($code !== null) {
                $variants[] = new FileVariant(
                    $code,
                    $values,
                    self::details($item['details'] ?? null, "{$where} › details", $problems),
                    $measures[0],
                    $measures[1],
                    $measures[2],
                    $measures[3],
                    self::photos($item['photos'] ?? null, self::VARIANT_PHOTOS_MAX, "{$where} › photos", $zip, $problems),
                );
            }
        }

        return $variants;
    }

    /**
     * @return array<string, string>
     */
    private static function values(mixed $raw, string $at, FileProblems $problems): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || array_is_list($raw) && $raw !== []) {
            $problems->add($at, 'an object: attribute name → value name');

            return [];
        }

        $values = [];

        foreach ($raw as $attribute => $value) {
            $name = self::listName((string) $attribute, "{$at} › {$attribute}", $problems);
            $valueName = self::listName($value, "{$at} › {$attribute}", $problems);

            if ($name !== null && $valueName !== null) {
                $values[$name] = $valueName;
            }
        }

        return $values;
    }

    /**
     * The values as search compares names, attribute by attribute, for "never two alike".
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private static function combination(array $values): array
    {
        $key = [];

        foreach ($values as $attribute => $value) {
            $key[ArabicText::normalize($attribute)] = ArabicText::normalize($value);
        }

        ksort($key);

        return $key;
    }

    /**
     * @return array<string, array{ar: string, en: string}|string>
     */
    private static function details(mixed $raw, string $at, FileProblems $problems): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || array_is_list($raw) && $raw !== []) {
            $problems->add($at, 'an object: attribute name → text in both languages, or a number');

            return [];
        }

        $details = [];

        foreach ($raw as $attribute => $value) {
            $where = "{$at} › {$attribute}";
            $name = self::listName((string) $attribute, $where, $problems);

            try {
                if (is_int($value) || is_float($value) || is_string($value) && is_numeric($value)) {
                    $detail = VariantDetail::number((string) $value);
                    $kept = (string) $detail->number;
                } elseif (is_array($value) && is_string($value['ar'] ?? null) && is_string($value['en'] ?? null) && count($value) === 2) {
                    $detail = VariantDetail::text($value['ar'], $value['en']);
                    $kept = ['ar' => (string) $detail->textAr, 'en' => (string) $detail->textEn];
                } else {
                    throw new InvalidCatalogAttribute('details', 'text in both languages, {"ar": …, "en": …}, or a number');
                }
            } catch (InvalidCatalogAttribute $error) {
                $problems->add($where, $error->reason);

                continue;
            }

            if ($name !== null) {
                $details[$name] = $kept;
            }
        }

        if (count($details) > 100) {
            $problems->add($at, 'at most 100');
        }

        return $details;
    }

    /**
     * @param  array<string, int>|null  $zip
     * @return list<string>
     */
    private static function photos(mixed $raw, int $max, string $at, ?array $zip, FileProblems $problems): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            $problems->add($at, 'a list of paths inside the zip');

            return [];
        }

        if ($zip === null) {
            $problems->add($at, 'photos come in the zip with the file; uploaded alone, the file names none');

            return [];
        }

        if (count($raw) > $max) {
            $problems->add($at, "at most {$max}");
        }

        $paths = [];

        foreach ($raw as $path) {
            if (! is_string($path) || trim($path) === '') {
                $problems->add($at, 'paths, as text');

                continue;
            }

            $path = self::path(trim($path));

            if (in_array($path, $paths, true)) {
                $problems->add($at, "{$path} named twice");
            } elseif (! isset($zip[$path])) {
                $problems->add($at, "{$path} is not in the zip");
            }

            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return list<string>
     */
    private static function searchWords(mixed $raw, string $at, FileProblems $problems): array
    {
        if ($raw === null) {
            return [];
        }

        try {
            if (! is_array($raw) || ! array_is_list($raw)) {
                throw new InvalidCatalogAttribute('search_words', 'a list of words');
            }

            return array_map(static fn (array $word): string => $word['word'], SearchWords::of($raw)->words);
        } catch (InvalidCatalogAttribute $error) {
            $problems->add($at, $error->reason);
        } catch (TooMany $error) {
            $problems->add($at, "at most {$error->max}");
        }

        return [];
    }

    /**
     * @return array<string, list<string>>
     */
    private static function filters(mixed $raw, string $at, FileProblems $problems): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || array_is_list($raw) && $raw !== []) {
            $problems->add($at, 'an object: filter attribute name → a list of value names');

            return [];
        }

        $filters = [];
        $count = 0;

        foreach ($raw as $attribute => $values) {
            $where = "{$at} › {$attribute}";
            $name = self::listName((string) $attribute, $where, $problems);

            if (! is_array($values) || ! array_is_list($values) || $values === []) {
                $problems->add($where, 'a list of value names');

                continue;
            }

            $names = [];

            foreach ($values as $value) {
                $valueName = self::listName($value, $where, $problems);

                if ($valueName !== null) {
                    $names[] = $valueName;
                }
            }

            $count += count($names);

            if ($name !== null) {
                $filters[$name] = $names;
            }
        }

        if ($count > ProductParts::MAX_FILTER_VALUES) {
            $problems->add($at, 'at most '.ProductParts::MAX_FILTER_VALUES.' values');
        }

        return $filters;
    }

    /**
     * @return list<string>
     */
    private static function codes(mixed $raw, string $at, FileProblems $problems): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || ! array_is_list($raw)) {
            $problems->add($at, 'a list of codes');

            return [];
        }

        if (count($raw) > ProductParts::MAX_RELATIONS) {
            $problems->add($at, 'at most '.ProductParts::MAX_RELATIONS);
        }

        $codes = [];

        foreach ($raw as $code) {
            try {
                $codes[] = is_string($code) ? ProductCode::of($code)->value : throw new InvalidCatalogAttribute('code', 'codes as text: 1 to 10 digits, in quotes');
            } catch (InvalidCatalogAttribute $error) {
                $problems->add($at, $error->reason);
            }
        }

        return array_values(array_unique($codes));
    }

    /**
     * @return array<string, array{price: string|null, stock: int|null}>
     */
    private static function stores(mixed $raw, string $at, FileProblems $problems): array
    {
        if ($raw === null) {
            return [];
        }

        if (! is_array($raw) || array_is_list($raw) && $raw !== []) {
            $problems->add($at, 'an object: store code → its price and stock');

            return [];
        }

        $stores = [];

        foreach ($raw as $code => $terms) {
            $where = "{$at} › {$code}";

            if (preg_match('/\A[a-z]{2,8}\z/', (string) $code) !== 1) {
                $problems->add($where, 'a store code as in the panel: 2 to 8 small letters');

                continue;
            }

            if (! is_array($terms) || array_is_list($terms) && $terms !== []) {
                $problems->add($where, 'an object with "price" and "stock", each optional');

                continue;
            }

            self::onlyFields($terms, ['price', 'stock'], $where, $problems);
            $stores[(string) $code] = [
                'price' => self::price($terms['price'] ?? null, "{$where} › price", false, $problems),
                'stock' => self::stock($terms['stock'] ?? null, "{$where} › stock", $problems),
            ];
        }

        return $stores;
    }

    /**
     * A price as the file wrote it — a number of at least 0 — kept as text, so nothing is lost
     * before Pricing reads it (stage 5).
     */
    public static function price(mixed $raw, string $at, bool $required, FileProblems $problems): ?string
    {
        if ($raw === null && ! $required) {
            return null;
        }

        if ((is_int($raw) || is_float($raw)) && $raw >= 0 && is_finite((float) $raw)) {
            return is_int($raw) ? (string) $raw : rtrim(rtrim(sprintf('%.6F', $raw), '0'), '.');
        }

        $problems->add($at, 'a number of at least 0');

        return null;
    }

    public static function stock(mixed $raw, string $at, FileProblems $problems): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (is_int($raw) && $raw >= 0) {
            return $raw;
        }

        $problems->add($at, 'a whole number of at least 0');

        return null;
    }
}
