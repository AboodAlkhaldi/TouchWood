<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Content\Application\Command\RequestSitemapRebuild\RequestSitemapRebuild;
use Modules\Content\Application\Command\RequestSitemapRebuild\RequestSitemapRebuildHandler;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaults;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaultsHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Application\Query\Seo\SeoHandler;
use Modules\Content\Presentation\Http\Resource\SeoPageData;
use Shared\Domain\Error\DomainError;

/*
| W10 · SEO (content.md §4.4, §1.14): the store's defaults and robots lines, saved together, and Rebuild Sitemap, which
| queues the build (one at a time: "Queued", out of reach while one waits). Under `content.seo.manage` in the store.
*/
final readonly class SeoController
{
    /** @var list<string> */
    private const array WORDS = ['content::admin_seo'];

    /** @var list<string> */
    private const array BOXES = ['site_name_ar', 'site_name_en', 'title_pattern_ar', 'title_pattern_en', 'description_ar', 'description_en', 'share_image', 'robots_extra'];

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, SeoHandler $seo, ContentActionsForReader $actions): Response|JsonResponse
    {
        $store = $this->screens->store($request, ContentPermissions::SEO_MANAGE);
        $read = $seo->page($store->id);

        if ($request->expectsJson()) {
            return new JsonResponse($read);
        }

        $defaults = $read['defaults'] ?? [];
        $share = $defaults['share_media_id'] ?? null;

        return $this->screens->render('Content/Admin/Seo', (new SeoPageData(
            $this->screens->stores(ContentPermissions::SEO_MANAGE),
            $store->code,
            $defaults['site_name_ar'] ?? null,
            $defaults['site_name_en'] ?? null,
            $defaults['title_pattern_ar'] ?? null,
            $defaults['title_pattern_en'] ?? null,
            $defaults['description_ar'] ?? null,
            $defaults['description_en'] ?? null,
            $share === null ? null : ($this->screens->images([$share])[strtolower($share)] ?? null),
            (string) ($defaults['robots_extra'] ?? ''),
            $read['sitemap']['builtAt'],
            $read['sitemap']['urls'],
            url("/{$store->code}/sitemap.xml"),
            $read['sitemap']['rebuildWaits'],
            $actions->translationReady(),
        ))->toArray(), self::WORDS);
    }

    public function save(Request $request, SaveSeoDefaultsHandler $save): RedirectResponse
    {
        try {
            $save->handle(new SaveSeoDefaults(
                (string) Inputs::text($request, 'store'),
                Inputs::typed($request, 'site_name_ar'),
                Inputs::typed($request, 'site_name_en'),
                Inputs::typed($request, 'title_pattern_ar'),
                Inputs::typed($request, 'title_pattern_en'),
                Inputs::typed($request, 'description_ar'),
                Inputs::typed($request, 'description_en'),
                Inputs::text($request, 'share_media_id'),
                Inputs::typed($request, 'robots_extra'),
            ));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::BOXES);
        }

        return back()->with('status', __('content::admin_seo.saved'));
    }

    public function rebuild(Request $request, RequestSitemapRebuildHandler $rebuild): RedirectResponse
    {
        try {
            $queued = $rebuild->handle(new RequestSitemapRebuild((string) Inputs::text($request, 'store')));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __($queued ? 'content::admin_seo.sitemap.queued' : 'content::admin_seo.sitemap.waits'));
    }
}
