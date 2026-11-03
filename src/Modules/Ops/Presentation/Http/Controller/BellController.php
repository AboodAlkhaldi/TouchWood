<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Controller;

use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Ops\Application\Command\MarkAllInboxRead\MarkAllInboxRead;
use Modules\Ops\Application\Command\MarkAllInboxRead\MarkAllInboxReadHandler;
use Modules\Ops\Application\Command\MarkInboxEntryRead\MarkInboxEntryRead;
use Modules\Ops\Application\Command\MarkInboxEntryRead\MarkInboxEntryReadHandler;
use Modules\Ops\Application\Query\CountUnread\CountUnread;
use Modules\Ops\Application\Query\CountUnread\CountUnreadHandler;
use Modules\Ops\Application\Query\ViewInbox\ViewInbox;
use Modules\Ops\Application\Query\ViewInbox\ViewInboxHandler;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Ops\Presentation\Http\Resource\BellPages;
use Modules\Ops\Presentation\Http\Resource\UnreadData;

/*
| The two bells (ops.md §1.2, §4): the shop's, for a signed-in customer in the store being shopped, and
| the panel's, for a staff member over the stores they may view. One controller serves both: no route
| names a person or a store's inbox - each handler reads who is asking, and answers with their own
| entries only.
|
| JSON, as the bell's menu asks for it in the page it sits on: the list (`after` is the cursor the page
| before gave), the number, and the two marks, each answering with the number after it. A plain visit to
| the list gets the full page behind View All (§4.15 O1, O2), its words carried with the frame.
*/
final readonly class BellController
{
    public function __construct(
        private BellPages $pages,
        private Page $page,
    ) {}

    public function panel(Request $request, ViewInboxHandler $view): JsonResponse|Response
    {
        return $this->list($request, $view, 'Ops/Admin/Notifications', ['admin']);
    }

    public function shop(Request $request, ViewInboxHandler $view): JsonResponse|Response
    {
        return $this->list($request, $view, 'Ops/Storefront/Notifications', StorefrontArea::WORDS);
    }

    /** @param  list<string>  $words */
    private function list(Request $request, ViewInboxHandler $view, string $component, array $words): JsonResponse|Response
    {
        $locale = app()->getLocale();

        if ($request->expectsJson()) {
            return new JsonResponse($this->pages->page($view->handle(new ViewInbox($locale, self::after($request))), $locale)->toArray());
        }

        $after = self::after($request);

        try {
            $inbox = $view->handle(new ViewInbox($locale, $after));
        } catch (InvalidOpsInput) {
            $inbox = null;
        }

        // A cursor typed by hand, gone with the nightly removal, or someone else's: the page opens at its start.
        if ($inbox === null || ($after !== null && $inbox->rows === [])) {
            $inbox = $view->handle(new ViewInbox($locale, null));
        }

        return $this->page->render($component, ['inbox' => $this->pages->page($inbox, $locale)->toArray()], $words);
    }

    public function unread(CountUnreadHandler $count): JsonResponse
    {
        return new JsonResponse((new UnreadData($count->handle(new CountUnread)))->toArray());
    }

    public function read(string $entry, MarkInboxEntryReadHandler $mark): JsonResponse
    {
        return new JsonResponse((new UnreadData($mark->handle(new MarkInboxEntryRead($entry))))->toArray());
    }

    public function readAll(MarkAllInboxReadHandler $mark, CountUnreadHandler $count): JsonResponse
    {
        $mark->handle(new MarkAllInboxRead);

        // Counted again rather than assumed 0: an entry may have arrived meanwhile.
        return new JsonResponse((new UnreadData($count->handle(new CountUnread)))->toArray());
    }

    /** The cursor a page gave, as it gave it: at most 64 characters, else the first page. */
    private static function after(Request $request): ?string
    {
        $after = $request->query('after');

        return is_string($after) && $after !== '' && strlen($after) <= 64 ? $after : null;
    }
}
