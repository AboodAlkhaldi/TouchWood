<?php

declare(strict_types=1);

use Modules\Catalog\Application\Import\DescriptionText;
use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ProductsFile;
use Modules\Catalog\Application\Import\StoreFillFile;
use Modules\Catalog\Domain\Exception\ImportRefused;

/*
| The two files, read and checked (catalog.md §1.12, §1.3, amendment 6; the guide in
| docs/modules/catalog-import/): the guide's own examples read as the guide says; every rule of its
| §1.7 and §2 refuses a file; every problem is collected, never only the first; descriptions turn
| into the structured text the panel keeps.
*/

function catalogImportExample(string $file): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/docs/modules/catalog-import/'.$file);
}

/**
 * A product the format accepts, to break one rule at a time.
 *
 * @return array<string, mixed>
 */
function catalogImportProduct(string $code = '1304'): array
{
    return [
        'name' => ['ar' => 'درج', 'en' => 'Drawer'],
        'attribute_set' => 'Sizes',
        'variants' => [
            ['code' => $code, 'values' => ['Width' => '60 cm']],
            ['code' => $code, 'values' => ['Width' => '80 cm']],
        ],
        'photos' => ['photos/a.jpg'],
    ];
}

/**
 * @param  list<array<string, mixed>>  $products
 * @param  list<string>|null  $zip
 * @return list<array{at: string, problem: string}>
 */
function catalogImportProblems(array $products, ?array $zip = ['photos/a.jpg', 'photos/b.jpg'], ?string $json = null): array
{
    try {
        ProductsFile::read($json ?? json_encode(['format' => 'touchwood-products/1', 'products' => $products], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $zip);
    } catch (ImportRefused $refused) {
        return $refused->problems;
    }

    return [];
}

describe('the guide\'s examples', function () {
    it('reads the example product file exactly as the guide describes it', function () {
        $file = ProductsFile::read(catalogImportExample('products.example.json'), ['photos/1304-45-zinc.jpg', 'photos/1304-front.jpg', 'photos/1304-open.webp', 'photos/2001.png']);
        [$runner, $hinge, $handle] = $file->products;

        expect($file->products)->toHaveCount(3)
            ->and($runner)->toBeInstanceOf(FileProduct::class)
            ->and([$runner->nameAr, $runner->nameEn, $runner->slugEn])->toBe(['مجرى درج تلسكوبي ناعم الإغلاق', 'Soft-close telescopic drawer runner', 'soft-close-drawer-runner'])
            ->and($runner->descriptionEn['blocks'][0] ?? null)->toBe(['type' => 'heading', 'runs' => [['text' => 'Soft-close drawer runner']]])
            ->and($runner->descriptionEn['blocks'][2]['items'][0] ?? null)->toBe([['text' => 'Holds up to '], ['text' => '35 kg', 'bold' => true]])
            ->and([$runner->brand, $runner->brandNumber, $runner->category, $runner->warranty, $runner->attributeSet])->toBe(['TouchWood', null, ['Kitchens', 'Drawers', 'Runners'], 'Two years', 'Runner sizes'])
            ->and($runner->codes())->toBe(['1304', '1305'])
            ->and($runner->variants[0]->values)->toBe(['Length' => '45 cm', 'Finish' => 'Zinc'])
            ->and($runner->variants[0]->details)->toBe(['Load' => '35', 'Material' => ['ar' => 'فولاذ', 'en' => 'Steel']])
            ->and([$runner->variants[0]->weightGrams, $runner->variants[0]->lengthMm, $runner->variants[1]->widthMm])->toBe([1450, 450, null])
            ->and($runner->variants[0]->photos)->toBe(['photos/1304-45-zinc.jpg'])
            ->and($runner->photos)->toBe(['photos/1304-front.jpg', 'photos/1304-open.webp'])
            ->and($runner->allPhotos())->toBe(['photos/1304-front.jpg', 'photos/1304-open.webp', 'photos/1304-45-zinc.jpg'])
            ->and($runner->searchWords)->toBe(['سحاب درج', 'مجرى', 'slide', 'rail'])
            ->and($runner->filters)->toBe(['Use' => ['Kitchen', 'Wardrobe'], 'Closing' => ['Soft-close']])
            ->and([$runner->related, $runner->goesWith])->toBe([['1306'], ['2001']])
            ->and([$hinge->brand, $hinge->brandNumber, $hinge->category, $hinge->attributeSet, $hinge->codes()])->toBe([null, 2, ['Tallsen', 'Hinges'], null, ['2001']])
            ->and([$handle->nameAr, $handle->nameEn, $handle->descriptionAr, $handle->codes()])->toBe(['مقبض ألمنيوم 128 مم', null, null, ['1306']]);

        // Kept as it was read, for the import's page and the step that brings it in.
        expect(FileProduct::fromArray($runner->toArray()))->toEqual($runner)
            ->and(FileProduct::fromArray($hinge->toArray()))->toEqual($hinge);
    });

    it('reads the example store file', function () {
        $items = StoreFillFile::read(catalogImportExample('store-fill.example.json'))->items;

        expect(array_map(static fn ($item): array => [$item->number, $item->code, $item->price, $item->stock], $items))->toBe([
            [1, '1304', '120.5', 40],
            [2, '1305', '125', null],
            [3, '2001', '18', 300],
        ]);
    });

    it('refuses the example product file uploaded alone: its photos come in the zip', function () {
        $problems = catalogImportProblems([], null, catalogImportExample('products.example.json'));

        expect(array_column($problems, 'at'))->toBe(['product 1 › variants 1 › photos', 'product 1 › photos', 'product 2 › photos'])
            ->and($problems[0]['problem'])->toContain('come in the zip');
    });
});

describe('what refuses a product file', function () {
    it('refuses it for each rule broken, naming where', function (Closure $break, string $at, string $problem) {
        $product = catalogImportProduct();
        $products = $break($product);

        $problems = catalogImportProblems(is_array($products) && array_is_list($products) ? $products : [$products]);

        expect($problems)->not->toBe([])
            ->and(array_column($problems, 'at'))->toContain($at)
            ->and(implode(' | ', array_column(array_filter($problems, static fn (array $item): bool => $item['at'] === $at), 'problem')))->toContain($problem);
    })->with([
        'a field the format does not have' => [fn (array $p) => [...$p, 'colour' => 'red'], 'product 1 › colour', 'not a field'],
        'a brand number below 1' => [fn (array $p) => [...$p, 'brand' => 0], 'product 1 › brand', "the brand's number, from 1, or its name"],
        'a brand neither a number nor a name' => [fn (array $p) => [...$p, 'brand' => 2.5], 'product 1 › brand', "the brand's number, from 1, or its name"],
        'no Arabic name' => [fn (array $p) => [...$p, 'name' => ['en' => 'Drawer']], 'product 1 › name', 'Arabic name'],
        'a name over 200 characters' => [fn (array $p) => [...$p, 'name' => ['ar' => str_repeat('د', 201)]], 'product 1 › name', '200'],
        'a slug not in its shape' => [fn (array $p) => [...$p, 'slug' => ['en' => 'Not A Slug']], 'product 1 › slug › en', 'lower-case Latin letters and digits'],
        'a description not text' => [fn (array $p) => [...$p, 'description' => ['ar' => 5]], 'product 1 › description › ar', 'text'],
        'a category path with an empty name' => [fn (array $p) => [...$p, 'category' => 'Kitchens /  / Runners'], 'product 1 › category', 'required'],
        'no variant' => [fn (array $p) => [...$p, 'variants' => []], 'product 1 › variants', 'at least one'],
        'a code not digits' => [fn (array $p) => [...$p, 'variants' => [['code' => '13a4', 'values' => ['Width' => '60 cm']]]], 'product 1 › variants 1 › code', 'digits'],
        'a code written as a number' => [fn (array $p) => [...$p, 'variants' => [['code' => 1304, 'values' => ['Width' => '60 cm']]]], 'product 1 › variants 1 › code', 'in quotes'],
        'two variants alike' => [fn (array $p) => [...$p, 'variants' => [['code' => '1304', 'values' => ['Width' => '60 cm']], ['code' => '1304', 'values' => ['width' => '60 CM']]]], 'product 1 › variants 2 › values', 'never alike'],
        'values without a set' => [fn (array $p) => array_diff_key($p, ['attribute_set' => true]), 'product 1 › variants 1 › values', 'attribute_set'],
        'a set without values' => [fn (array $p) => [...$p, 'variants' => [['code' => '1304']]], 'product 1 › variants 1 › values', 'one value for each'],
        'two variants with no set' => [fn (array $p) => [...array_diff_key($p, ['attribute_set' => true]), 'variants' => [['code' => '1'], ['code' => '2']]], 'product 1 › variants', 'one variant'],
        'variants made of other attributes' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['Width' => '60 cm']], ['code' => '1', 'values' => ['Finish' => 'Zinc']]]], 'product 1 › variants 2 › values', 'same attributes'],
        'a measure not a whole number' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['Width' => '60 cm'], 'weight_g' => 2.5]]], 'product 1 › variants 1 › weight_g', 'whole number'],
        'a measure out of range' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['Width' => '60 cm'], 'length_mm' => 0]]], 'product 1 › variants 1 › length_mm', 'a whole number from 1 to'],
        'a detail neither text in both languages nor a number' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['Width' => '60 cm'], 'details' => ['Material' => ['ar' => 'فولاذ']]]]], 'product 1 › variants 1 › details › Material', 'both languages'],
        'a photo not in the zip' => [fn (array $p) => [...$p, 'photos' => ['photos/missing.jpg']], 'product 1 › photos', 'not in the zip'],
        'a photo named twice' => [fn (array $p) => [...$p, 'photos' => ['photos/a.jpg', './photos/a.jpg']], 'product 1 › photos', 'named twice'],
        'more than 20 gallery photos' => [fn (array $p) => [...$p, 'photos' => array_fill(0, 21, 'photos/a.jpg')], 'product 1 › photos', 'at most 20'],
        'more than 10 photos of a variant' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['Width' => '60 cm'], 'photos' => array_map(static fn (int $n): string => "photos/{$n}.jpg", range(1, 11))]]], 'product 1 › variants 1 › photos', 'at most 10'],
        'more than 30 search words' => [fn (array $p) => [...$p, 'search_words' => array_map(static fn (int $n): string => "word{$n}", range(1, 31))], 'product 1 › search_words', 'at most 30'],
        'filters not lists of values' => [fn (array $p) => [...$p, 'filters' => ['Use' => 'Kitchen']], 'product 1 › filters › Use', 'list of value names'],
        'more than 20 related products' => [fn (array $p) => [...$p, 'related' => array_map(static fn (int $n): string => (string) (2000 + $n), range(1, 21))], 'product 1 › related', 'at most 20'],
        'a related code not digits' => [fn (array $p) => [...$p, 'goes_with' => ['20x1']], 'product 1 › goes_with', 'digits'],
        'a store named in a products file' => [fn (array $p) => [...$p, 'stores' => ['sa' => ['price' => 1]]], 'product 1 › stores', "not in a products file: each store's own file brings its prices and stock"],
        'two products sharing a code' => [fn (array $p) => [$p, catalogImportProduct('1304')], 'products 1, 2', 'never share a code'],
        'two variants alike, their attribute named in digits' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['2' => '60 cm']], ['code' => '1', 'values' => ['2' => '60 CM']]]], 'product 1 › variants 2 › values', 'never alike'],
    ]);

    it('refuses a file not in the format at all', function (string $json, string $at, string $problem) {
        $problems = catalogImportProblems([], json: $json);

        expect($problems[0]['at'] ?? null)->toBe($at)
            ->and($problems[0]['problem'] ?? '')->toContain($problem);
    })->with([
        'not JSON' => ['{"format": ', 'file', 'valid JSON'],
        'not an object' => ['[1, 2]', 'file', 'an object'],
        'another format' => ['{"format": "touchwood-products/2", "products": [{"name": {"ar": "درج"}, "variants": [{"code": "1"}]}]}', 'format', 'touchwood-products/1'],
        'no products' => ['{"format": "touchwood-products/1", "products": []}', 'products', '1 to 2,000'],
        'a product not an object' => ['{"format": "touchwood-products/1", "products": ["drawer"]}', 'product 1', 'an object'],
    ]);

    it('collects every problem of the file, not only the first', function () {
        $problems = catalogImportProblems([
            [...catalogImportProduct('1'), 'name' => ['en' => 'No Arabic']],
            [...catalogImportProduct('2'), 'photos' => ['photos/missing.jpg']],
            [...catalogImportProduct('3'), 'goes_with' => ['20x1']],
        ]);

        expect(array_column($problems, 'at'))->toBe(['product 1 › name', 'product 2 › photos', 'product 3 › goes_with']);
    });

    it('takes a file that keeps every rule', function () {
        expect(catalogImportProblems([catalogImportProduct('1'), catalogImportProduct('2')]))->toBe([]);
    });
});

describe('what refuses a store file', function () {
    it('refuses it for each rule broken, naming where', function (string $json, string $at, string $problem) {
        try {
            StoreFillFile::read($json);
            $problems = [];
        } catch (ImportRefused $refused) {
            $problems = $refused->problems;
        }

        expect(array_column($problems, 'at'))->toContain($at)
            ->and(implode(' | ', array_column(array_filter($problems, static fn (array $item): bool => $item['at'] === $at), 'problem')))->toContain($problem);
    })->with([
        'another format' => ['{"format": "touchwood-products/1", "items": [{"code": "1", "price": 1}]}', 'format', 'touchwood-store-fill/1'],
        'no items' => ['{"format": "touchwood-store-fill/1", "items": []}', 'items', '1 to 1,000'],
        'no price' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "1304"}]}', 'item 1 › price', 'at least 0'],
        'a code written as a number' => ['{"format": "touchwood-store-fill/1", "items": [{"code": 1304, "price": 1}]}', 'item 1 › code', 'in quotes'],
        'a code twice' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "1304", "price": 1}, {"code": "1304", "price": 2}]}', 'item 2 › code', 'item 1 names it'],
        'a field the format does not have' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "1304", "price": 1, "store": "sa"}]}', 'item 1 › store', 'not a field'],
        'a stock below 0' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "1304", "price": 1, "stock": -1}]}', 'item 1 › stock', 'at least 0'],
    ]);
});

describe('a description in plain text', function () {
    it('turns paragraphs, list items, headings and bold into the structured text the panel keeps', function () {
        expect(DescriptionText::document("# Title\nFirst line\nsecond line.\n\n- one **bold** item\n- two\nAfter the list"))->toBe(['blocks' => [
            ['type' => 'heading', 'runs' => [['text' => 'Title']]],
            ['type' => 'paragraph', 'runs' => [['text' => 'First line second line.']]],
            ['type' => 'list', 'items' => [[['text' => 'one '], ['text' => 'bold', 'bold' => true], ['text' => ' item']], [['text' => 'two']]]],
            ['type' => 'paragraph', 'runs' => [['text' => 'After the list']]],
        ]]);
    });

    it('keeps an unmatched marker, and anything that looks like HTML, as the text it is', function () {
        expect(DescriptionText::document('Price ** only <b>here</b>'))->toBe(['blocks' => [
            ['type' => 'paragraph', 'runs' => [['text' => 'Price '], ['text' => '** only <b>here</b>']]],
        ]]);
    });
});
