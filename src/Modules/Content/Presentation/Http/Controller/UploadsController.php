<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Modules\Content\Application\Command\UploadBannerImage\UploadBannerImage;
use Modules\Content\Application\Command\UploadBannerImage\UploadBannerImageHandler;
use Modules\Content\Application\Command\UploadBlogImage\UploadBlogImage;
use Modules\Content\Application\Command\UploadBlogImage\UploadBlogImageHandler;
use Modules\Content\Application\Command\UploadHomeImage\UploadHomeImage;
use Modules\Content\Application\Command\UploadHomeImage\UploadHomeImageHandler;
use Modules\Content\Application\Command\UploadPageImage\UploadPageImage;
use Modules\Content\Application\Command\UploadPageImage\UploadPageImageHandler;
use Modules\Content\Application\Command\UploadPopupImage\UploadPopupImage;
use Modules\Content\Application\Command\UploadPopupImage\UploadPopupImageHandler;
use Modules\Content\Application\Command\UploadProjectImage\UploadProjectImage;
use Modules\Content\Application\Command\UploadProjectImage\UploadProjectImageHandler;
use Modules\Content\Application\Command\UploadSeoImage\UploadSeoImage;
use Modules\Content\Application\Command\UploadSeoImage\UploadSeoImageHandler;
use Modules\Content\Application\Command\UploadThemeImage\UploadThemeImage;
use Modules\Content\Application\Command\UploadThemeImage\UploadThemeImageHandler;
use Modules\Content\Domain\Exception\InvalidContentInput;

/**
 * An image put into an item's upload box (content.md §4.2, §1.1 rule 10): `POST /admin/content/uploads/{kind}` with the
 * file and the store, answered with the file as the box shows it. Uploaded under the item's own permission in its
 * store, by that kind's handler (one a permission, lesson 175); nothing is saved on the item until its form is.
 */
final readonly class UploadsController
{
    public function __invoke(
        Request $request,
        string $kind,
        ContentScreens $screens,
        UploadHomeImageHandler $home,
        UploadBannerImageHandler $banner,
        UploadPopupImageHandler $popup,
        UploadPageImageHandler $page,
        UploadBlogImageHandler $blog,
        UploadProjectImageHandler $project,
        UploadSeoImageHandler $seo,
        UploadThemeImageHandler $theme,
    ): JsonResponse {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw new InvalidContentInput('file', InvalidContentInput::IMAGE_FILE);
        }

        $store = Inputs::text($request, 'store');
        $path = (string) $file->getRealPath();
        $name = $file->getClientOriginalName();

        $id = match ($kind) {
            'homepage' => $home->handle(new UploadHomeImage($store, $path, $name)),
            'banner' => $banner->handle(new UploadBannerImage($store, $path, $name)),
            'popup' => $popup->handle(new UploadPopupImage($store, $path, $name)),
            'page' => $page->handle(new UploadPageImage($store, $path, $name)),
            'blog' => $blog->handle(new UploadBlogImage($store, $path, $name)),
            'project' => $project->handle(new UploadProjectImage($store, $path, $name)),
            'seo' => $seo->handle(new UploadSeoImage($store, $path, $name)),
            'theme' => $theme->handle(new UploadThemeImage($store, $path, $name)),
            default => throw new InvalidContentInput('kind', InvalidContentInput::KIND),
        };

        $image = $screens->images([$id])[$id] ?? null;

        return new JsonResponse($image?->toArray(), 201);
    }
}
