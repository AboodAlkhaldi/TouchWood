<?php

declare(strict_types=1);

namespace Modules\Sync\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Sync\Presentation\Http\Resource\SyncPages;

/**
 * **A read** (sync.md §4.1 Y2, `?section=&page=`): its header and one section, a page of 100 at a time
 * (Q32). One pruned after 90 days, of a store the reader holds nothing of Sync's in, or never one, is the
 * not-found page (§9.3 #29): the read asks for View the Sync Report in its store; this checks nothing.
 */
final readonly class SyncReadController
{
    /** @var list<string> */
    public const array WORDS = ['sync::admin_report', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function show(Request $request, string $read, SyncPages $pages): Response
    {
        $page = SyncForms::query($request, 'page');

        // Made at once, not lazily: a read that is not there is the not-found page, never a page of nulls.
        return $this->page->render('Sync/Admin/Read', $pages->read(
            $read,
            SyncForms::query($request, 'section'),
            $page !== null && preg_match('/\A[0-9]{1,6}\z/', $page) === 1 ? (int) $page : 1,
        )->toArray(), self::WORDS);
    }
}
