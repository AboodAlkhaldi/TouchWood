<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Import;

use Closure;
use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\Command\AddAttributeSet\AddAttributeSet;
use Modules\Catalog\Application\Command\AddAttributeSet\AddAttributeSetHandler;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValue;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValueHandler;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariant;
use Modules\Catalog\Application\Command\RestoreVariant\RestoreVariantHandler;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValues;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValuesHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWords;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWordsHandler;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotos;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotosHandler;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariant;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariantHandler;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ProductNotFound;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\StoreListingRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Catalog\Domain\ValueObject\VariantDetail;
use Modules\Catalog\Public\Enums\AttributeKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Throwable;

/**
 * **Bringing an import's products in: the work** (catalog.md §1.12, page part 3; amendments 6, 7),
 * inside the one transaction `BringInImportProductsHandler` holds, under the products' lock and the
 * lists':
 *
 * 1. **The names decided "create it"** are made through the lists' own handlers — categories a parent
 *    before its children, values under their attribute, sets with the attributes their products give
 *    them. One under a name refused is refused with it.
 * 2. **Each product, in the file's order, as the page's changes left it**: skipped; **held back** when
 *    something it cannot come in without was refused — its set, or a value or attribute of a variant;
 *    otherwise **created as a draft** through the product handlers, its photos added to the media
 *    library, or **the catalog's product updated** — what the file gives replacing what it has, the
 *    rest kept — or **replaced whole** — what the file leaves out cleared, its variants the file does
 *    not name archived. A refused brand is the default brand; another name refused is left out.
 *
 * Every handler checks as it does for a person, so a product a ready rule refuses is named and
 * nothing is kept (`ImportStepFailed`).
 */
final readonly class ImportBringer
{
    public function __construct(
        private Imports $imports,
        private PlatformApi $platform,
        private BrandRepository $brands,
        private CategoryRepository $categories,
        private AttributeRepository $attributes,
        private WarrantyRepository $warranties,
        private ProductRepository $products,
        private VariantRepository $variants,
        private AddCategoryHandler $addCategory,
        private AddAttributeValueHandler $addValue,
        private AddAttributeSetHandler $addSet,
        private CreateProductHandler $createProduct,
        private EditProductDetailsHandler $editDetails,
        private AddVariantHandler $addVariant,
        private UpdateVariantHandler $updateVariant,
        private ArchiveVariantHandler $archiveVariant,
        private SetProductGalleryHandler $setGallery,
        private SetVariantPhotosHandler $setVariantPhotos,
        private SetSearchWordsHandler $setSearchWords,
        private SetFilterValuesHandler $setFilterValues,
        private RestoreVariantHandler $restoreVariant,
        private SetRelationsHandler $setRelations,
        private StoreListingRepository $listings,
        private ListingRows $listingRows,
    ) {}

    /**
     * @param  array<string, string>  $files  the zip's photos, unpacked before the locks were taken: its path => the file
     * @return array<string, int> how many products came in, were updated, replaced, skipped or held back
     *
     * @throws ImportStepFailed
     */
    public function bringIn(ImportHeader $import, array $files): array
    {
        // Those left out at upload take no part (amendment 11(a)): they stay as they were refused.
        $rows = ImportProduct::takingPart($this->imports->products($import->id));
        $names = [];

        foreach ($this->imports->names($import->id) as $name) {
            $names[$name->kind.' '.$name->key] = $name;
        }

        $catalog = CatalogNames::load($this->brands, $this->categories, $this->attributes, $this->warranties);
        $created = $this->createLists($names, $catalog, $rows);
        $default = $this->brands->defaultBrand()?->id() ?? throw new LogicException('No default brand: the seed makes one.');
        $references = new ImportReferences($catalog, $names, $created, $default);
        // Products are made from a store that is on, as in the panel (amendment 3(j)); the first will do.
        $store = $this->platform->stores()[0] ?? null;
        $photos = new ImportPhotos($this->platform, $files);
        $results = [];
        $counts = ['IN' => 0, 'UPDATED' => 0, 'REPLACED' => 0, 'SKIPPED' => 0, 'HELD' => 0];

        foreach ($rows as $row) {
            [$productId, $state] = $this->step("product {$row->number}", fn (): array => $this->bringInOne($row, $references, $store, $photos));
            $results[$row->id] = ['product_id' => $productId, 'state' => $state];
            $counts[$state]++;
        }

        $this->imports->recordResults($results);

        return $counts;
    }

    /**
     * @return array{string|null, string} the product it became, and its state
     */
    private function bringInOne(ImportProduct $row, ImportReferences $references, ?StoreDto $store, ImportPhotos $photos): array
    {
        if ($row->decision === ImportProduct::SKIP) {
            return [null, 'SKIPPED'];
        }

        // What the page asked to fill is given where the product has none as it is now (§1.12).
        $product = $row->effective()->asBroughtIn($row->decision === ImportProduct::UPDATE ? $this->catalogHas((string) $row->conflictProductId) : null);
        $resolved = self::resolve($product, $references);

        if ($resolved === null) {
            return [null, 'HELD'];
        }

        // Put on sale since the confirm asked (amendment 9(c)): keep or take off is chosen, never assumed.
        if (in_array($row->decision, [ImportProduct::UPDATE, ImportProduct::REPLACE], true) && $row->sale === null && $this->listings->activeStoresOf((string) $row->conflictProductId) !== []) {
            throw new InvalidCatalogAttribute('sale', 'keep on sale or take off sale: it went on sale after the confirm');
        }

        $result = match ($row->decision) {
            ImportProduct::UPDATE => [$this->update((string) $row->conflictProductId, $product, $resolved, false, $photos), 'UPDATED'],
            ImportProduct::REPLACE => [$this->update((string) $row->conflictProductId, $product, $resolved, true, $photos), 'REPLACED'],
            default => [$this->create($product, $row->newCodes ?? [], $resolved, $store, $photos), 'IN'],
        };

        // Taken off sale as the Super Admin chose (amendment 9(c)): off in every store, still ready.
        if ($row->sale === ImportProduct::TAKE_OFF_SALE) {
            $this->takeOffSale((string) $result[0]);
        }

        return $result;
    }

    /**
     * What the catalog's product the file updates has now, for what the page asked to fill.
     *
     * @return array{warranty: bool, category: bool, search_words: bool, filters: bool}
     */
    private function catalogHas(string $productId): array
    {
        $product = $this->products->find($productId) ?? throw new ProductNotFound($productId);

        return [
            'warranty' => $product->warrantyId() !== null,
            'category' => $product->categoryId() !== null,
            'search_words' => $this->products->searchWords($productId) !== [],
            'filters' => $this->catalogFilters($productId) !== [],
        ];
    }

    /**
     * Switched off in every store, each store's change audited there, as archiving does (§1.3) — the
     * product itself kept as it is, ready.
     */
    private function takeOffSale(string $productId): void
    {
        foreach ($this->listings->inEveryStore($productId) as $listing) {
            $listing->switchOff();
            $entry = ListAudit::changed('listing', 'chosen', $productId, $listing->pullChanges(), $listing->snapshot(), $listing->storeId());

            if ($entry !== null) {
                $this->listings->save($listing);
                $this->platform->recordAudit($entry);
            }
        }

        $this->listingRows->refresh([$productId]);
    }

    /**
     * Its names as the catalog has them now and as decided — or null when it is held back.
     *
     * @return array{brand: string, givenBrand: string|null, category: string|null, warranty: string|null, set: string|null, variants: list<array{values: array<string, string>, details: array<string, array<string, string>>, file: FileVariant}>, filters: list<string>}|null
     */
    private static function resolve(FileProduct $product, ImportReferences $references): ?array
    {
        $set = null;

        if ($product->attributeSet !== null) {
            $set = $references->set($product->attributeSet);

            // Its variants are made of the set's values: without it, they cannot come in.
            if ($set === null) {
                return null;
            }
        }

        $variants = [];

        foreach ($product->variants as $variant) {
            $values = [];

            foreach ($variant->values as $attribute => $value) {
                $attributeId = $references->attribute((string) $attribute);
                $valueId = $attributeId === null ? null : $references->value((string) $attribute, $attributeId, $value);

                // A variant's value is what makes it: refused, the product is held back.
                if ($attributeId === null || $valueId === null) {
                    return null;
                }

                $values[$attributeId] = $valueId;
            }

            $details = [];

            foreach ($variant->details as $attribute => $detail) {
                $attributeId = $references->attribute((string) $attribute);

                if ($attributeId !== null) {
                    $details[$attributeId] = is_array($detail) ? ['text_ar' => $detail['ar'], 'text_en' => $detail['en']] : ['number' => $detail];
                }
            }

            $variants[] = ['values' => $values, 'details' => $details, 'file' => $variant];
        }

        $filters = $product->filterValueIds;

        foreach ($product->filters as $attribute => $values) {
            $attributeId = $references->attribute((string) $attribute);

            foreach ($attributeId === null ? [] : $values as $value) {
                $valueId = $references->value((string) $attribute, (string) $attributeId, $value);

                if ($valueId !== null) {
                    $filters[] = $valueId;
                }
            }
        }

        return [
            'brand' => $references->brand($product),
            'givenBrand' => $references->givenBrand($product),
            'category' => $references->category($product),
            'warranty' => $references->warranty($product),
            'set' => $set,
            'variants' => $variants,
            'filters' => array_values(array_unique($filters)),
        ];
    }

    /**
     * A new draft, through the handlers a person's form uses.
     *
     * @param  array<string, string>  $newCodes  each code it gives up => its new one
     * @param  array{brand: string, givenBrand: string|null, category: string|null, warranty: string|null, set: string|null, variants: list<array{values: array<string, string>, details: array<string, array<string, string>>, file: FileVariant}>, filters: list<string>}  $resolved
     */
    private function create(FileProduct $product, array $newCodes, array $resolved, ?StoreDto $store, ImportPhotos $photos): string
    {
        $storeId = ($store ?? throw new InvalidCatalogAttribute('store', 'a store that is on'))->id;
        $id = $this->createProduct->handle(new CreateProduct($storeId, $product->nameAr, $product->nameEn, $resolved['brand'], $product->slugAr, $product->slugEn));
        $this->editDetails->handle(new EditProductDetails($id, $product->nameAr, $product->nameEn, $resolved['brand'], $product->slugAr, $product->slugEn, $product->descriptionAr, $product->descriptionEn, $resolved['category'], $resolved['warranty'], $resolved['set']));

        foreach ($resolved['variants'] as $position => $variant) {
            $file = $variant['file'];
            $variantId = $this->addVariant->handle(new AddVariant($id, $newCodes[$file->code] ?? $file->code, $variant['values'], $variant['details'], $file->weightGrams, $file->lengthMm, $file->widthMm, $file->heightMm, $position));

            if ($file->photos !== []) {
                $this->setVariantPhotos->handle(new SetVariantPhotos($variantId, $photos->ids($file->photos)));
            }
        }

        $this->parts($id, $product, $resolved['filters'], false, false, $photos);

        return $id;
    }

    /**
     * The catalog's product, updated with what the file gives, or replaced whole.
     *
     * @param  array{brand: string, givenBrand: string|null, category: string|null, warranty: string|null, set: string|null, variants: list<array{values: array<string, string>, details: array<string, array<string, string>>, file: FileVariant}>, filters: list<string>}  $resolved
     */
    private function update(string $productId, FileProduct $product, array $resolved, bool $replace, ImportPhotos $photos): string
    {
        $existing = $this->products->find($productId) ?? throw new ProductNotFound($productId);
        $this->editDetails->handle(new EditProductDetails(
            $productId,
            $product->nameAr,
            $replace ? $product->nameEn : $product->nameEn ?? $existing->name()->en,
            $replace ? $resolved['brand'] : $resolved['givenBrand'] ?? $existing->brandId(),
            $product->slugAr ?? ($replace ? null : $existing->slugs()->ar->value),
            $product->slugEn ?? ($replace ? null : $existing->slugs()->en?->value),
            $product->descriptionAr ?? ($replace ? null : $existing->descriptionAr()?->toArray()),
            $product->descriptionEn ?? ($replace ? null : $existing->descriptionEn()?->toArray()),
            $resolved['category'] ?? ($replace ? null : $existing->categoryId()),
            $resolved['warranty'] ?? ($replace ? null : $existing->warrantyId()),
            $resolved['set'] ?? ($replace ? null : $existing->attributeSetId()),
        ));

        // A variant of the file is the product's variant of the same values.
        $current = [];

        foreach ($this->variants->ofProduct($productId) as $variant) {
            $current[self::combination($variant->combination()->valueIds)] = $variant;
        }

        $named = [];

        foreach ($resolved['variants'] as $position => $variant) {
            $file = $variant['file'];
            $match = $current[self::combination($variant['values'])] ?? null;

            if ($match === null) {
                $variantId = $this->addVariant->handle(new AddVariant($productId, $file->code, $variant['values'], $variant['details'], $file->weightGrams, $file->lengthMm, $file->widthMm, $file->heightMm, $position));
            } else {
                $variantId = $match->id();

                // The file names it again: it comes back, as a restore in the panel brings it.
                if ($match->isArchived()) {
                    $this->restoreVariant->handle(new RestoreVariant($variantId));
                }

                $named[$variantId] = true;
                $measures = $match->measures();
                $this->updateVariant->handle(new UpdateVariant(
                    $variantId,
                    $file->code,
                    $variant['values'],
                    $replace || $variant['details'] !== [] ? $variant['details'] : self::detailsInput($match->details()),
                    $file->weightGrams ?? ($replace ? null : $measures->weightGrams),
                    $file->lengthMm ?? ($replace ? null : $measures->lengthMm),
                    $file->widthMm ?? ($replace ? null : $measures->widthMm),
                    $file->heightMm ?? ($replace ? null : $measures->heightMm),
                    $replace ? $position : $match->position(),
                ));
            }

            if ($replace || $file->photos !== []) {
                $this->setVariantPhotos->handle(new SetVariantPhotos($variantId, $photos->ids($file->photos)));
            }
        }

        if ($replace) {
            foreach ($current as $variant) {
                if (! isset($named[$variant->id()]) && ! $variant->isArchived()) {
                    $this->archiveVariant->handle(new ArchiveVariant($variant->id()));
                }
            }
        }

        $this->parts($productId, $product, $resolved['filters'], $replace, ! $replace, $photos);

        // Replaced whole: its related products go too — the file's are linked when it is accepted.
        if ($replace) {
            foreach (['RELATED', 'GOES_WITH'] as $kind) {
                $this->setRelations->handle(new SetRelations($productId, $kind, []));
            }
        }

        return $productId;
    }

    /**
     * The gallery, search words and filter values: set when the file gives them (or the page filled
     * them), or always when replacing. Words and values the page added (amendment 8(a)) join the
     * file's — or, for a product the file updates without giving any, the catalog's as they are now.
     *
     * @param  list<string>  $filters
     */
    private function parts(string $productId, FileProduct $product, array $filters, bool $replace, bool $updating, ImportPhotos $photos): void
    {
        if ($replace || $product->photos !== []) {
            $this->setGallery->handle(new SetProductGallery($productId, $photos->ids($product->photos)));
        }

        $words = $product->searchWords;

        if ($product->addedSearchWords !== []) {
            $base = $updating && $words === [] ? array_map(static fn (array $word): string => $word['word'], $this->products->searchWords($productId)) : $words;
            $words = array_values(array_unique([...$base, ...$product->addedSearchWords]));
        }

        if ($replace || $words !== []) {
            $this->setSearchWords->handle(new SetSearchWords($productId, $words));
        }

        if ($product->addedFilterValueIds !== []) {
            $base = $updating && $filters === [] ? $this->catalogFilters($productId) : $filters;
            $filters = array_values(array_unique([...$base, ...$product->addedFilterValueIds]));
        }

        if ($replace || $filters !== []) {
            $this->setFilterValues->handle(new SetFilterValues($productId, $filters));
        }
    }

    /**
     * A catalog product's filter values of filter attributes — a variant's own values count as filters
     * by themselves (amendment 3(a)).
     *
     * @return list<string>
     */
    private function catalogFilters(string $productId): array
    {
        $ids = [];

        foreach ($this->products->filterValues($productId) as $valueId => $attributeId) {
            if ($this->attributes->find((string) $attributeId)?->kind() === AttributeKind::Filterable) {
                $ids[] = (string) $valueId;
            }
        }

        return $ids;
    }

    /**
     * The names decided "create it", made — each under what it hangs on, or refused with it.
     *
     * @param  array<string, ImportName>  $names
     * @param  list<ImportProduct>  $rows
     * @return array<string, string> kind and key => the item made
     *
     * @throws ImportStepFailed
     */
    private function createLists(array $names, CatalogNames $catalog, array $rows): array
    {
        $created = [];
        $create = array_filter($names, static fn (ImportName $name): bool => $name->decision === ImportName::CREATE);

        // Categories, a parent before its children.
        $categories = array_filter($create, static fn (ImportName $name): bool => $name->kind === ImportNameRow::CATEGORY);
        uasort($categories, static fn (ImportName $a, ImportName $b): int => substr_count($a->written, ' / ') <=> substr_count($b->written, ' / '));

        foreach ($categories as $key => $name) {
            $parentPath = array_slice(explode(' / ', $name->written), 0, -1);
            $parent = $parentPath === [] ? null : $catalog->category($parentPath) ?? self::picked(ImportNameRow::CATEGORY, $parentPath, $names, $created);

            if ($parentPath === [] || $parent !== null) {
                $created[$key] = $this->step("the category {$name->written}", fn (): string => $this->addCategory->handle(new AddCategory((string) $name->nameAr, (string) $name->nameEn, $parent, 0, $name->slugAr, $name->slugEn)));
            }
        }

        foreach ($create as $key => $name) {
            if ($name->kind !== ImportNameRow::VALUE) {
                continue;
            }

            $attribute = (string) $name->attribute;
            $attributeId = $catalog->attribute($attribute)?->id() ?? self::picked(ImportNameRow::ATTRIBUTE, [$attribute], $names, $created);

            if ($attributeId !== null) {
                $created[$key] = $this->step("the value {$name->written} of {$attribute}", fn (): string => $this->addValue->handle(new AddAttributeValue($attributeId, (string) $name->nameAr, (string) $name->nameEn)));
            }
        }

        foreach ($create as $key => $name) {
            if ($name->kind !== ImportNameRow::SET) {
                continue;
            }

            $members = self::setMembers($name, $rows, static fn (string $attribute): ?string => $catalog->attribute($attribute)?->id() ?? self::picked(ImportNameRow::ATTRIBUTE, [$attribute], $names, $created));

            if ($members !== null) {
                $created[$key] = $this->step("the set {$name->written}", fn (): string => $this->addSet->handle(new AddAttributeSet((string) $name->nameAr, (string) $name->nameEn, $members)));
            }
        }

        return $created;
    }

    /**
     * A name the catalog lacks, as decided: the one it means, or the one made for it already — null
     * when refused, or made under a name refused.
     *
     * @param  list<string>  $written  what keyOf() reads
     * @param  array<string, ImportName>  $names
     * @param  array<string, string>  $created
     */
    private static function picked(string $kind, array $written, array $names, array $created): ?string
    {
        $key = $kind.' '.ImportNameRow::keyOf($written);
        $name = $names[$key] ?? null;

        return match ($name?->decision) {
            ImportName::EXISTING => $name->targetId,
            ImportName::CREATE => $created[$key] ?? null,
            default => null,
        };
    }

    /**
     * A new set's attributes: those its first product's variants are made of (the file is refused when
     * two products give it different ones) — or null when one of them was refused.
     *
     * @param  list<ImportProduct>  $rows
     * @param  Closure(string): ?string  $attribute
     * @return list<string>|null
     */
    private static function setMembers(ImportName $set, array $rows, Closure $attribute): ?array
    {
        foreach ($rows as $row) {
            $product = $row->effective();

            if ($product->attributeSet !== null && ImportNameRow::keyOf([$product->attributeSet]) === $set->key) {
                $members = array_map(static fn (int|string $name): ?string => $attribute((string) $name), array_keys(($product->variants[0] ?? null)->values ?? []));

                return in_array(null, $members, true) ? null : array_values(array_filter($members));
            }
        }

        return null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     *
     * @throws ImportStepFailed
     */
    private function step(string $where, Closure $work): mixed
    {
        try {
            return $work();
        } catch (ImportStepFailed $failed) {
            throw $failed;
        } catch (Throwable $error) {
            throw new ImportStepFailed($where, $error);
        }
    }

    /**
     * @param  list<ImportProduct>  $rows
     * @return list<string> every photo the products coming in name, each once
     */
    public static function photoPaths(array $rows): array
    {
        $paths = [];

        foreach ($rows as $row) {
            if ($row->decision !== ImportProduct::SKIP) {
                array_push($paths, ...$row->effective()->allPhotos());
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<string, string>  $valueIds  attribute id => value id
     */
    public static function combination(array $valueIds): string
    {
        ksort($valueIds);

        return implode(',', array_map(static fn (int|string $attribute, string $value): string => "{$attribute}={$value}", array_keys($valueIds), $valueIds));
    }

    /**
     * A variant's details as its form takes them, to keep them as they are.
     *
     * @param  array<string, VariantDetail>  $details
     * @return array<string, array<string, string>>
     */
    private static function detailsInput(array $details): array
    {
        return array_map(static fn (VariantDetail $detail): array => $detail->number !== null
            ? ['number' => $detail->number]
            : ['text_ar' => (string) $detail->textAr, 'text_en' => (string) $detail->textEn], $details);
    }
}
