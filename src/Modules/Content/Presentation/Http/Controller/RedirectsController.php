<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Response;
use Modules\Content\Application\Command\AddRedirect\AddRedirect;
use Modules\Content\Application\Command\AddRedirect\AddRedirectHandler;
use Modules\Content\Application\Command\ApplyRedirectSheet\ApplyRedirectSheet;
use Modules\Content\Application\Command\ApplyRedirectSheet\ApplyRedirectSheetHandler;
use Modules\Content\Application\Command\ChangeRedirect\ChangeRedirect;
use Modules\Content\Application\Command\ChangeRedirect\ChangeRedirectHandler;
use Modules\Content\Application\Command\DeleteRedirect\DeleteRedirect;
use Modules\Content\Application\Command\DeleteRedirect\DeleteRedirectHandler;
use Modules\Content\Application\Command\RequestRedirectCheck\RequestRedirectCheck;
use Modules\Content\Application\Command\RequestRedirectCheck\RequestRedirectCheckHandler;
use Modules\Content\Application\Command\ResetRedirectCount\ResetRedirectCount;
use Modules\Content\Application\Command\ResetRedirectCount\ResetRedirectCountHandler;
use Modules\Content\Application\Command\UploadRedirectSheet\UploadRedirectSheet;
use Modules\Content\Application\Command\UploadRedirectSheet\UploadRedirectSheetHandler;
use Modules\Content\Application\Command\UseFinalAddress\UseFinalAddress;
use Modules\Content\Application\Command\UseFinalAddress\UseFinalAddressHandler;
use Modules\Content\Application\Query\Redirects\RedirectsHandler;
use Modules\Content\Domain\Exception\SheetRefused;
use Modules\Content\Presentation\Http\Resource\RedirectRowData;
use Modules\Content\Presentation\Http\Resource\RedirectSheetPageData;
use Modules\Content\Presentation\Http\Resource\RedirectsPageData;
use Modules\Content\Presentation\Http\Resource\SheetLineData;
use Shared\Domain\Error\DomainError;

/*
| W11 · Redirects and W11b, the checked sheet (content.md §4.4, §1.15): the lines, most visited first, searched; a line
| added, changed, its count reset, its final address used, deleted; Check Addresses queued; a sheet uploaded, shown line
| by line, and written only by Apply Sheet. Super Admins alone, store-free (`content.redirect.manage`). A line is named
| by its old address, sent in the body: an address holds slashes.
*/
final readonly class RedirectsController
{
    /** @var list<string> */
    private const array WORDS = ['content::admin_redirects'];

    /** @var list<string> */
    private const array BOXES = ['old_path', 'new_path'];

    private const string LIST = '/admin/content/redirects';

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, RedirectsHandler $redirects): Response|JsonResponse
    {
        $read = $redirects->list(Inputs::text($request, 'q'), Inputs::text($request, 'after'));
        $rows = array_map(RedirectRowData::fromRow(...), $read['rows']);

        if ($request->expectsJson()) {
            return new JsonResponse(['rows' => array_map(static fn (RedirectRowData $row): array => $row->toArray(), $rows), 'next' => $read['next']]);
        }

        return $this->screens->render('Content/Admin/Redirects/Index', (new RedirectsPageData($rows, $read['next'], Inputs::text($request, 'q') ?? '', $read['checkWaits']))->toArray(), self::WORDS);
    }

    public function add(Request $request, AddRedirectHandler $add): RedirectResponse
    {
        return self::act($request, fn () => $add->handle(new AddRedirect(Inputs::text($request, 'old_path'), Inputs::text($request, 'new_path'))), 'content::admin_redirects.added', self::BOXES);
    }

    public function change(Request $request, ChangeRedirectHandler $change): RedirectResponse
    {
        return self::act($request, fn () => $change->handle(new ChangeRedirect((string) Inputs::text($request, 'line'), Inputs::text($request, 'old_path'), Inputs::text($request, 'new_path'))), 'content::admin_redirects.changed', self::BOXES);
    }

    public function reset(Request $request, ResetRedirectCountHandler $reset): RedirectResponse
    {
        return self::act($request, fn () => $reset->handle(new ResetRedirectCount((string) Inputs::text($request, 'line'))), 'content::admin_redirects.reset_done', []);
    }

    public function final(Request $request, UseFinalAddressHandler $final): RedirectResponse
    {
        return self::act($request, fn () => $final->handle(new UseFinalAddress((string) Inputs::text($request, 'line'))), 'content::admin_redirects.final_done', []);
    }

    public function delete(Request $request, DeleteRedirectHandler $delete): RedirectResponse
    {
        return self::act($request, fn () => $delete->handle(new DeleteRedirect((string) Inputs::text($request, 'line'))), 'content::admin_redirects.deleted', []);
    }

    public function check(Request $request, RequestRedirectCheckHandler $check): RedirectResponse
    {
        try {
            $queued = $check->handle(new RequestRedirectCheck);
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return back()->with('status', __($queued ? 'content::admin_redirects.check.queued' : 'content::admin_redirects.check.waits'));
    }

    public function upload(Request $request, UploadRedirectSheetHandler $upload): RedirectResponse
    {
        $file = $request->file('sheet');

        try {
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw new SheetRefused(SheetRefused::TYPE);
            }

            $id = $upload->handle(new UploadRedirectSheet((string) $file->getRealPath(), $file->getClientOriginalName(), (int) $file->getSize()));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return redirect(self::LIST."/sheets/{$id}");
    }

    public function sheet(Request $request, string $upload, RedirectsHandler $redirects): Response
    {
        $read = $redirects->sheet($upload);
        $tab = Inputs::text($request, 'tab');

        return $this->screens->render('Content/Admin/Redirects/Sheet', (new RedirectSheetPageData(
            strtolower($upload),
            array_map(SheetLineData::fromLine(...), $read['lines']),
            $read['counts'],
            $read['applied'],
            in_array($tab, ['NEW', 'SAME', 'CHANGE', 'REFUSED'], true) ? $tab : 'NEW',
        ))->toArray(), self::WORDS);
    }

    public function apply(Request $request, string $upload, ApplyRedirectSheetHandler $apply): RedirectResponse
    {
        try {
            $done = $apply->handle(new ApplyRedirectSheet($upload));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, []);
        }

        return redirect(self::LIST)->with('status', __('content::admin_redirects.sheet.applied', ['added' => $done['added'], 'changed' => $done['changed']]));
    }

    /**
     * @param  callable(): mixed  $change
     * @param  list<string>  $boxes
     */
    private static function act(Request $request, callable $change, string $toast, array $boxes): RedirectResponse
    {
        try {
            $change();
        } catch (DomainError $error) {
            return Refusals::back($request, $error, $boxes);
        }

        return back()->with('status', __($toast));
    }
}
