<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use Illuminate\Http\RedirectResponse;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabels;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabelsHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\ClearNotAvailableNow\ClearNotAvailableNow;
use Modules\Catalog\Application\Command\ClearNotAvailableNow\ClearNotAvailableNowHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Platform\Public\Contracts\PlatformApi;

/**
 * What a product's Stores tab changes in one store (catalog.md §1.3, §4.4 S9 "Stores"), each through
 * its own handler, which asks for its own job in that store; this checks nothing more. Refusals are
 * said where the panel says them (`CatalogRefusals`).
 *
 * The store is named in the address by its code, as `?store=` names it on every store screen (and as
 * Platform's pattern for `{store}` asks); a code that is no store is passed on as it is, and refused as
 * the job would be.
 */
final readonly class ProductStoresController
{
    /** Switches the whole product on or off there, or the variants named. */
    public function choose(CatalogFormRequest $request, string $product, string $store, PlatformApi $platform, ChooseInStoreHandler $handler): RedirectResponse
    {
        $variants = $request->input('variant_ids');

        return CatalogRefusals::act($request, fn () => $handler->handle(new ChooseInStore(
            self::storeId($platform, $store),
            $product,
            $request->boolean('active'),
            is_array($variants) ? $request->texts('variant_ids') : null,
        )), $request->boolean('active') ? 'catalog::admin_products.toast.store_on' : 'catalog::admin_products.toast.store_off');
    }

    /**
     * Each variant's ways of selling there and the product's quantities - a switch sends the stored
     * quantities with it, as the command keeps them all at once.
     */
    public function selling(CatalogFormRequest $request, string $product, string $store, PlatformApi $platform, SetSellingTermsHandler $handler): RedirectResponse
    {
        $modes = $request->input('modes');

        return CatalogRefusals::act($request, fn () => $handler->handle(new SetSellingTerms(
            self::storeId($platform, $store),
            $product,
            is_array($modes) ? $modes : [],
            $request->number('retail_minimum'),
            $request->optionalNumber('retail_maximum'),
            $request->optionalNumber('wholesale_minimum'),
            $request->optionalNumber('wholesale_maximum'),
        )), 'catalog::admin_products.toast.selling', ['retail_minimum', 'retail_maximum', 'wholesale_minimum', 'wholesale_maximum']);
    }

    /** The whole product, or one variant, not available now there - or available again. */
    public function unavailable(CatalogFormRequest $request, string $product, string $store, PlatformApi $platform, MarkNotAvailableNowHandler $mark, ClearNotAvailableNowHandler $clear): RedirectResponse
    {
        $variant = $request->optionalText('variant_id');
        $store = self::storeId($platform, $store);

        return $request->boolean('unavailable')
            ? CatalogRefusals::act($request, fn () => $mark->handle(new MarkNotAvailableNow($store, $product, $variant)), 'catalog::admin_products.toast.not_available')
            : CatalogRefusals::act($request, fn () => $clear->handle(new ClearNotAvailableNow($store, $product, $variant)), 'catalog::admin_products.toast.available');
    }

    /** The labels it carries there, at most 10. */
    public function labels(CatalogFormRequest $request, string $product, string $store, PlatformApi $platform, AttachLabelsHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new AttachLabels(self::storeId($platform, $store), $product, $request->texts('label_ids'))), 'catalog::admin_products.toast.labels', ['labels']);
    }

    /** The store's id, by the code in the address; a code that is no store stays as it came. */
    private static function storeId(PlatformApi $platform, string $code): string
    {
        return $platform->storeByCode($code)->id ?? $code;
    }
}
