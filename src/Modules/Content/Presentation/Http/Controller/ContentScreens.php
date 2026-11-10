<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\CategoryPathDto;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Domain\Exception\StoreOff;
use Modules\Content\Presentation\Http\Resource\CategoryChoiceData;
use Modules\Content\Presentation\Http\Resource\ContentImageData;
use Modules\Content\Presentation\Http\Resource\ContentStoreData;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Platform\Public\Enums\ImageFormat;
use Modules\Platform\Public\Enums\MediaSize;
use Shared\Application\Unauthorized;

/**
 * What every Content screen does the same way (content.md §4.2): the store it shows, chosen by its code in the address
 * among the stores the person holds the job in (`StoreFilter`: the first that is on when none is asked); the stores on
 * offer; an item's images as their upload boxes show them; the categories a listing or a smart bar may name; and the
 * page drawn with its own words.
 *
 * Offering is not allowing: every handler behind a screen asks for its permission again.
 */
final readonly class ContentScreens
{
    public function __construct(
        private StoreChoices $choices,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private Page $page,
        private ContentAccess $access,
    ) {}

    /**
     * An off store asked for by someone who holds the job there but may not switch stores is refused in the spec's words
     * (`content.store_off`, §1.1 rule 7); any other store not offered, plainly.
     *
     * @throws Unauthorized|StoreOff
     */
    public function store(Request $request, string $permission): StoreDto
    {
        $code = Inputs::text($request, 'store');

        try {
            return $this->choices->chosen($code, $permission) ?? throw new Unauthorized($permission);
        } catch (Unauthorized $refused) {
            $this->access->refuseIfOff($code, $permission);

            throw $refused;
        }
    }

    /**
     * @return list<ContentStoreData>
     */
    public function stores(string $permission): array
    {
        return ContentStoreData::listOf($this->choices->forJobs($permission));
    }

    /**
     * Each image named, as its box shows it: two queries for any number of them.
     *
     * @param  list<string|null>  $mediaIds
     * @return array<string, ContentImageData> keyed by the media id
     */
    public function images(array $mediaIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (?string $id): ?string => $id === null ? null : strtolower($id), $mediaIds))));

        if ($ids === []) {
            return [];
        }

        $files = $this->platform->mediaOf($ids);
        $urls = $this->platform->mediaUrlsOf($ids);
        $images = [];

        foreach ($files as $id => $file) {
            $images[$id] = new ContentImageData($id, $file->originalFilename, $file->width, $file->height, $file->variantsStatus->value ?? 'READY', self::thumbnail($urls[$id]->variants ?? []));
        }

        return $images;
    }

    /**
     * The categories switched on, each with its path: what a smart bar and a listing banner may name (§1.6, §1.9).
     *
     * @return list<CategoryChoiceData>
     */
    public function categories(): array
    {
        return array_map(static fn (CategoryPathDto $category): CategoryChoiceData => new CategoryChoiceData(
            strtolower($category->id),
            implode(' › ', array_map(static fn (TranslatedTextDto $name): string => $name->ar, $category->path)),
            implode(' › ', array_map(static fn (TranslatedTextDto $name): string => $name->en, $category->path)),
        ), $this->catalog->activeCategories());
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  list<string>  $words  the page's own word files; Content's errors and the panel's come with every page
     */
    public function render(string $component, array $props, array $words): Response
    {
        return $this->page->render($component, $props, [...$words, 'content::admin_shared', 'content::errors', 'admin']);
    }

    /**
     * @param  array<string, array<string, string>>  $variants
     */
    private static function thumbnail(array $variants): ?string
    {
        $sizes = $variants[MediaSize::Thumb->slug()] ?? $variants[MediaSize::Card->slug()] ?? [];

        foreach ([ImageFormat::Webp, ImageFormat::Jpeg, ImageFormat::Avif] as $format) {
            if (isset($sizes[$format->extension()])) {
                return $sizes[$format->extension()];
            }
        }

        return null;
    }
}
