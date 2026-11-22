<?php

declare(strict_types=1);

namespace Modules\Loyalty\Presentation\Http\Controller;

use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Loyalty\Application\Points\PointsTabOffered;
use Modules\Loyalty\Application\Query\ViewMyPoints\ViewMyPointsHandler;
use Modules\Loyalty\Presentation\Http\Resource\PointsPages;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\StoreContext;

/*
| L7 - the customer's own points in the shop's store (loyalty.md §4.1), a page of their account beside
| Access's own tabs (access.md amendment 50). It never names an account: the handler reads who is
| asking. Offered only while the store's programme is on or the customer has points there (answer
| 4; amendment 72(d)) - opened any other time, it is the shop's 404, as a page that is not there.
*/
final readonly class MyPointsController
{
    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS, 'loyalty::shop_points', 'loyalty::errors'];

    public function __construct(
        private Page $page,
    ) {}

    public function show(Request $request, ActorContext $actors, StoreContext $stores, PointsTabOffered $offered, PointsPages $pages, ViewMyPointsHandler $view): Response
    {
        $actor = $actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null || ! $offered->offers($actor->id)) {
            abort(404);
        }

        $before = $request->query('before_at');
        $beforeId = $request->query('before_id');

        return $this->page->render('Loyalty/Storefront/Points', $pages->mine(
            $stores,
            is_string($before) && $before !== '' ? $before : null,
            is_string($beforeId) && ctype_digit($beforeId) && strlen($beforeId) <= 18 ? (int) $beforeId : null,
            $view,
        )->toArray(), self::WORDS);
    }
}
