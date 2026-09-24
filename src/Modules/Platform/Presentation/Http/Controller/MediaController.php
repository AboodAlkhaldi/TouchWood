<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMedia;
use Modules\Platform\Application\Command\DeleteMedia\DeleteMediaHandler;
use Modules\Platform\Application\Command\RetryMediaVariants\RetryMediaVariants;
use Modules\Platform\Application\Command\RetryMediaVariants\RetryMediaVariantsHandler;
use Modules\Platform\Application\Command\UpdateMediaAltText\UpdateMediaAltText;
use Modules\Platform\Application\Command\UpdateMediaAltText\UpdateMediaAltTextHandler;
use Modules\Platform\Application\Command\UploadMedia\UploadMedia;
use Modules\Platform\Application\Command\UploadMedia\UploadMediaHandler;
use Modules\Platform\Application\Query\ListMedia\ListMediaHandler;
use Modules\Platform\Presentation\Http\Resource\MediaPages;
use Modules\Platform\Public\Enums\MediaVisibility;
use Shared\Domain\Error\DomainError;

/**
 * The media library (frontend.md 3.5, E5).
 *
 * Media belongs to no store, and its permissions are store-free, so every question here is global.
 * Whoever may add, describe or remove a file may look at the library; the read model refuses
 * anybody else, and each handler refuses again before it writes.
 *
 * A delete says where the file is used first, and Platform refuses it while a use blocks it
 * (platform.md 1.4) - a company's registration document is not something a tidy-up may remove.
 */
final readonly class MediaController
{
    /** @var list<string> */
    private const array WORDS = ['platform::admin_media', 'platform::errors', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private MediaPages $pages,
    ) {}

    /** E5. */
    public function index(Request $request, ListMediaHandler $media): Response
    {
        return $this->page->render('Platform/Admin/Media/Index', $this->pages->list(
            $media,
            $this->optional($request, 'after_at'),
            $this->optional($request, 'after_id'),
        )->toArray(), self::WORDS);
    }

    public function upload(Request $request, UploadMediaHandler $handler): RedirectResponse
    {
        $file = $request->file('file');

        if (! $file instanceof UploadedFile || $file->getRealPath() === false) {
            // Nothing arrived, or nothing readable did. Said in the form rather than as a 500:
            // the commonest cause is a file larger than the server accepts, which never reaches
            // the handler that would otherwise say so.
            return back()->withErrors(['form' => __('platform::admin_media.no_file')]);
        }

        try {
            $handler->handle(new UploadMedia(
                // A file is public unless it is said to be private: the private ones are the
                // exception, and the exception is the one worth saying out loud.
                $request->string('visibility')->toString() === MediaVisibility::Private->value
                    ? MediaVisibility::Private
                    : MediaVisibility::Public,
                $file->getRealPath(),
                $file->getClientOriginalName(),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['visibility']);
        }

        return back()->with('status', __('platform::admin_media.uploaded'));
    }

    public function describe(Request $request, string $mediaId, UpdateMediaAltTextHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new UpdateMediaAltText(
                $mediaId,
                $this->optional($request, 'alt_ar'),
                $this->optional($request, 'alt_en'),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['alt_ar', 'alt_en']);
        }

        return back()->with('status', __('platform::admin_media.described'));
    }

    public function retry(Request $request, string $mediaId, RetryMediaVariantsHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new RetryMediaVariants($mediaId));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('platform::admin_media.retrying'));
    }

    public function delete(Request $request, string $mediaId, DeleteMediaHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new DeleteMedia($mediaId));
        } catch (DomainError $error) {
            // Including a use that blocks it: the file stays, and the screen says which use.
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('platform::admin_media.deleted'));
    }

    private function optional(Request $request, string $field): ?string
    {
        $value = $request->string($field)->toString();

        return $value === '' ? null : $value;
    }
}
