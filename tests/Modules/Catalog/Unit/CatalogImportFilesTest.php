<?php

declare(strict_types=1);

use Modules\Catalog\Application\Import\DescriptionText;
use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\FileVariant;
use Modules\Catalog\Application\Import\ProductsFile;
use Modules\Catalog\Application\Import\StoreFillFile;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Catalog\Presentation\Http\Controller\ImportGuideController;

/*
| The two files, read and checked (catalog.md §1.12, §1.3, amendment 6; the guide in the module's
| Presentation/import-format/, amendment 42): the guide's own examples read as the guide says; every rule of its
| §1.7 and §2 refuses a file; every problem is collected, never only the first; descriptions turn
| into the structured text the panel keeps.
*/

function catalogImportExample(string $file): string
{
    return (string) file_get_contents(ImportGuideController::FOLDER.$file);
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
        'variants' => [
            ['code' => $code, 'values' => ['Width' => '60 cm']],
            // Every variant its own code (amendment 16(a)).
            ['code' => $code.'0', 'values' => ['Width' => '80 cm']],
        ],
        'photos' => ['photos/a.jpg'],
    ];
}

/**
 * As many variant-making attributes as asked, one value each.
 *
 * @return array<string, string>
 */
function catalogImportValues(int $count): array
{
    return array_combine(array_map(static fn (int $n): string => "Attribute {$n}", range(1, $count)), array_map(static fn (int $n): string => "Value {$n}", range(1, $count)));
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
            ->and([$runner->brand, $runner->brandNumber, $runner->category, $runner->warranty])->toBe(['TouchWood', null, ['Kitchens', 'Drawers', 'Runners'], 'Two years'])
            ->and($runner->codes())->toBe(['1304', '1314', '1305'])
            ->and($runner->variants[0]->values)->toBe(['Length' => '45 cm', 'Finish' => 'Zinc'])
            ->and($runner->variants[0]->details)->toBe(['Load' => '35', 'Material' => ['ar' => 'فولاذ', 'en' => 'Steel']])
            ->and([$runner->variants[0]->weightGrams, $runner->variants[0]->lengthMm, $runner->variants[1]->widthMm])->toBe([1450, 450, null])
            ->and($runner->variants[0]->photos)->toBe(['photos/1304-45-zinc.jpg'])
            ->and($runner->photos)->toBe(['photos/1304-front.jpg', 'photos/1304-open.webp'])
            ->and($runner->allPhotos())->toBe(['photos/1304-front.jpg', 'photos/1304-open.webp', 'photos/1304-45-zinc.jpg'])
            ->and($runner->searchWords)->toBe(['سحاب درج', 'مجرى', 'slide', 'rail'])
            ->and($runner->filters)->toBe(['Use' => ['Kitchen', 'Wardrobe'], 'Closing' => ['Soft-close']])
            ->and([$runner->related, $runner->goesWith])->toBe([['1306'], ['2001']])
            ->and([$hinge->brand, $hinge->brandNumber, $hinge->category, $hinge->codes()])->toBe([null, 2, ['Tallsen', 'Hinges'], ['2001']])
            ->and([$handle->nameAr, $handle->nameEn, $handle->descriptionAr, $handle->codes()])->toBe(['مقبض ألمنيوم 128 مم', null, null, ['1306']]);

        // A variant kept before as a map of values still reads; written now as pairs, in order.
        expect(FileVariant::fromArray(['code' => '1', 'values' => ['Length' => '45 cm', 'Finish' => 'Zinc']])->values)->toBe(['Length' => '45 cm', 'Finish' => 'Zinc'])
            ->and($runner->variants[0]->toArray()['values'])->toBe([['Length', '45 cm'], ['Finish', 'Zinc']]);

        // Kept as it was read, for the import's page and the step that brings it in.
        expect(FileProduct::fromArray($runner->toArray()))->toEqual($runner)
            ->and(FileProduct::fromArray($hinge->toArray()))->toEqual($hinge);
    });

    it('reads the example store file', function () {
        $items = StoreFillFile::read(catalogImportExample('store-fill.example.json'))->items;

        // Codes only (amendment 42): a store's prices and stock come from Odoo.
        expect(array_map(static fn ($item): array => [$item->number, $item->code], $items))->toBe([
            [1, '1304'],
            [2, '1314'],
            [3, '1305'],
            [4, '2001'],
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
        'two variants alike' => [fn (array $p) => [...$p, 'variants' => [['code' => '1304', 'values' => ['Width' => '60 cm']], ['code' => '1305', 'values' => ['width' => '60 CM']]]], 'product 1 › variants 2 › values', 'never alike'],
        'two variants sharing a code, typed once in Arabic digits' => [fn (array $p) => [...$p, 'variants' => [['code' => '1304', 'values' => ['Width' => '60 cm']], ['code' => '١٣٠٤', 'values' => ['Width' => '80 cm']]]], 'product 1 › variants 2 › code', 'not the code of variant 1: two variants never share a code'],
        'an attribute set, gone (amendment 16(b))' => [fn (array $p) => [...$p, 'attribute_set' => 'Sizes'], 'product 1 › attribute_set', "not in a products file: each variant's values name the attributes"],
        'two variants, one without values' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['Width' => '60 cm']], ['code' => '2']]], 'product 1 › variants 2 › values', 'a product with more than one variant has them'],
        'two variants, neither with values' => [fn (array $p) => [...$p, 'variants' => [['code' => '1'], ['code' => '2']]], 'product 1 › variants 1 › values', 'a product with more than one variant has them'],
        'variants made of other attributes' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['Width' => '60 cm']], ['code' => '2', 'values' => ['Finish' => 'Zinc']]]], 'product 1 › variants 2 › values', 'same attributes'],
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
        'a store named in a products file' => [fn (array $p) => [...$p, 'stores' => ['sa' => ['price' => 1]]], 'product 1 › stores', "not in a products file: a store's admins switch products on with their store file; prices and stock come from Odoo"],
        // The limits the guide states (amendment 42).
        'more than 10 attributes making the variants' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => catalogImportValues(11)]]], 'product 1 › variants 1 › values', 'at most 10 attributes'],
        'a brand name over 100 characters' => [fn (array $p) => [...$p, 'brand' => str_repeat('b', 101)], 'product 1 › brand', 'at most 100 characters'],
        'a warranty name over 100 characters' => [fn (array $p) => [...$p, 'warranty' => str_repeat('w', 101)], 'product 1 › warranty', 'at most 100 characters'],
        'a category level over 100 characters' => [fn (array $p) => [...$p, 'category' => 'Kitchens / '.str_repeat('c', 101)], 'product 1 › category', 'at most 100 characters'],
        'an attribute name over 100 characters' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => [str_repeat('a', 101) => '60 cm']]]], 'product 1 › variants 1 › values › '.str_repeat('a', 101), 'at most 100 characters'],
        'a value name over 100 characters' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['Width' => str_repeat('v', 101)]]]], 'product 1 › variants 1 › values › Width', 'at most 100 characters'],
        'a slug over 200 characters' => [fn (array $p) => [...$p, 'slug' => ['en' => str_repeat('s', 201)]], 'product 1 › slug › en', '200'],
        'two products sharing a code' => [fn (array $p) => [$p, catalogImportProduct('1304')], 'products 1, 2', 'the code 1304 on two products: two variants never share a code'],
        'two variants alike, their attribute named in digits' => [fn (array $p) => [...$p, 'variants' => [['code' => '1', 'values' => ['2' => '60 cm']], ['code' => '2', 'values' => ['2' => '60 CM']]]], 'product 1 › variants 2 › values', 'never alike'],
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

    it('takes a product at the limits the guide states: 10 attributes making its variants, names of 100 characters', function () {
        $name = static fn (string $letter): string => str_repeat($letter, ProductsFile::NAME_MAX);

        expect(catalogImportProblems([[
            ...catalogImportProduct(),
            'brand' => $name('b'),
            'warranty' => $name('w'),
            'category' => $name('c').' / '.$name('d'),
            'variants' => [
                ['code' => '1', 'values' => [...catalogImportValues(ProductsFile::VARIANT_ATTRIBUTES_MAX - 1), $name('a') => $name('v')]],
            ],
        ]]))->toBe([]);
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
        'another format' => ['{"format": "touchwood-products/1", "items": [{"code": "1"}]}', 'format', 'touchwood-store-fill/1'],
        'no items' => ['{"format": "touchwood-store-fill/1", "items": []}', 'items', '1 to 1,000'],
        'an item with no code' => ['{"format": "touchwood-store-fill/1", "items": [{}]}', 'item 1 › code', 'in quotes'],
        'a code written as a number' => ['{"format": "touchwood-store-fill/1", "items": [{"code": 1304}]}', 'item 1 › code', 'in quotes'],
        'a code of 11 digits' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "12345678901"}]}', 'item 1 › code', '1 to 10'],
        'a code twice' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "1304"}, {"code": "1304"}]}', 'item 2 › code', 'item 1 names it'],
        'a field the format does not have' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "1304", "store": "sa"}]}', 'item 1 › store', 'not a field'],
        // Amendment 42: the columns the format dropped, refused with why.
        'a price' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "1304", "price": 120.5}]}', 'item 1 › price', "a store's prices come from Odoo"],
        'a stock' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "1304", "stock": 40}]}', 'item 1 › stock', "a store's stock comes from Odoo"],
        'an empty price' => ['{"format": "touchwood-store-fill/1", "items": [{"code": "1304", "price": null}]}', 'item 1 › price', "a store's prices come from Odoo"],
    ]);

    it('reads an item as its code alone', function () {
        $items = StoreFillFile::read('{"format": "touchwood-store-fill/1", "items": [{"code": "1"}, {"code": "0012"}]}')->items;

        expect(array_map(static fn ($item): array => (array) $item, $items))->toBe([['number' => 1, 'code' => '1'], ['number' => 2, 'code' => '0012']]);
    });
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

describe('every number in Latin digits (amendment 12)', function () {
    it('reads a product file\'s Arabic digits as 0-9, wherever they were typed', function () {
        $file = ProductsFile::read((string) json_encode(['format' => 'touchwood-products/1', 'products' => [[
            ...catalogImportProduct('١٣٠٤'),
            'name' => ['ar' => 'درج ٦٠', 'en' => 'Drawer ۶۰'],
            'slug' => ['ar' => 'درج-٦٠'],
            'description' => ['ar' => 'يحمل **٢٥ كغ**'],
            'variants' => [
                ['code' => '١٣٠٤', 'values' => ['Width' => '٦٠ cm'], 'details' => ['Load' => '٢٥', 'Material' => ['ar' => 'فولاذ ٣٠٤', 'en' => 'Steel 304']]],
                ['code' => '1305', 'values' => ['Width' => '80 cm']],
            ],
            'search_words' => ['درج ٦٠'],
            'related' => ['١٣٠٦'],
        ]]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ['photos/a.jpg']);
        [$drawer] = $file->products;

        expect([$drawer->nameAr, $drawer->nameEn, $drawer->slugAr])->toBe(['درج 60', 'Drawer 60', 'درج-60'])
            ->and($drawer->descriptionAr)->toBe(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'يحمل '], ['text' => '25 كغ', 'bold' => true]]]]])
            // Typed as ١٣٠٤: the code 1304.
            ->and($drawer->codes())->toBe(['1304', '1305'])
            ->and($drawer->variants[0]->values)->toBe(['Width' => '60 cm'])
            ->and($drawer->variants[0]->details)->toBe(['Load' => '25', 'Material' => ['ar' => 'فولاذ 304', 'en' => 'Steel 304']])
            ->and([$drawer->searchWords, $drawer->related])->toBe([['درج 60'], ['1306']]);
    });

    it('reads a store file\'s code typed in Arabic digits as the 0-9 code, the same code as 1304', function () {
        expect(StoreFillFile::read('{"format": "touchwood-store-fill/1", "items": [{"code": "١٣٠٤"}]}')->items[0]->code)->toBe('1304');

        try {
            StoreFillFile::read('{"format": "touchwood-store-fill/1", "items": [{"code": "1304"}, {"code": "١٣٠٤"}]}');
            $problems = [];
        } catch (ImportRefused $refused) {
            $problems = $refused->problems;
        }

        expect($problems)->toBe([['at' => 'item 2 › code', 'problem' => '1304 again: item 1 names it already']]);
    });
});

describe('the guide', function () {
    it('states the limits the code holds', function () {
        $guide = catalogImportExample(ImportGuideController::GUIDE);

        expect($guide)
            // The code rule, at the top and in each file's part.
            ->toContain('A code is **1 to '.ProductCode::MAX_DIGITS.' digits, written as text in quotes**')
            ->toContain('`"1304"`, never `1304`')
            ->toContain('`products` holds **1 to '.number_format(ProductsFile::MAX_PRODUCTS).' products**. The JSON is at most **'.(ProductsFile::MAX_BYTES / 1024 / 1024).' MB**')
            ->toContain('**At most '.ProductsFile::VARIANT_ATTRIBUTES_MAX.' attributes** make a product\'s variants')
            ->toContain('**Each name is at most '.ProductsFile::NAME_MAX.' characters**')
            ->toContain('paths inside the zip**, at most '.ProductsFile::GALLERY_MAX.', each once')
            ->toContain('paths inside the zip, at most '.ProductsFile::VARIANT_PHOTOS_MAX.', each once')
            ->toContain('`items` holds **1 to '.number_format(StoreFillFile::MAX_ITEMS).'** items, **each code once**. The file is at most **'.(StoreFillFile::MAX_BYTES / 1024 / 1024).' MB**')
            ->toContain('**No `price` and no `stock`**')
            // Nothing left of the store file's old columns, or of the time before Pricing and Inventory.
            ->and([str_contains($guide, '"price":'), str_contains($guide, '"stock":'), str_contains($guide, 'stage 5')])->toBe([false, false, false]);
    });
});
