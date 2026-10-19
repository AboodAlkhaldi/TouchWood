<?php

declare(strict_types=1);

namespace Modules\Promotions\Presentation\Http\Controller;

use App\Http\AdminArea;
use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Promotions\Application\Command\ChangeSegment\ChangeSegment;
use Modules\Promotions\Application\Command\ChangeSegment\ChangeSegmentHandler;
use Modules\Promotions\Application\Command\ChangeSegmentMembers\ChangeSegmentMembers;
use Modules\Promotions\Application\Command\ChangeSegmentMembers\ChangeSegmentMembersHandler;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegment;
use Modules\Promotions\Application\Command\CreateSegment\CreateSegmentHandler;
use Modules\Promotions\Application\Command\DeleteSegment\DeleteSegment;
use Modules\Promotions\Application\Command\DeleteSegment\DeleteSegmentHandler;
use Modules\Promotions\Application\Command\RefreshSegment\RefreshSegment;
use Modules\Promotions\Application\Command\RefreshSegment\RefreshSegmentHandler;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\ListSegmentMembers\ListSegmentMembersHandler;
use Modules\Promotions\Application\Query\ListSegments\ListSegmentsHandler;
use Modules\Promotions\Application\Query\SearchSegmentCandidates\SearchSegmentCandidatesHandler;
use Modules\Promotions\Application\Query\ViewSegment\ViewSegment;
use Modules\Promotions\Application\Query\ViewSegment\ViewSegmentHandler;
use Modules\Promotions\Domain\Exception\InvalidPromotionAttribute;
use Modules\Promotions\Presentation\Http\Resource\SegmentPages;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Text\LatinDigits;
use Shared\Domain\ValueObject\StoreId;

/*
| Promotions' segment screens (promotions.md §4.1 M3): the store's segments, one segment's page with
| its members, and every change behind their buttons - New Segment…, Edit…, Refresh Now, Delete
| Segment…, and Save Members for a segment picked by hand.
|
| Who may open or change any of these is Promotions' answer, never the routing's: each read asks for a
| Marketing job in the segment's store, each change for `promotions.segment.manage` (§3).
*/
final readonly class SegmentsController
{
    /** @var list<string> */
    private const array WORDS = ['promotions::admin_segments', 'promotions::errors', 'admin'];

    private const string LIST = '/'.AdminArea::PREFIX.'/segments';

    /** The form's own boxes, where a refusal naming one is said. */
    private const array FIELDS = ['name', 'name_ar', 'name_en', 'segment_type', 'audience', 'rule', 'rule_months', 'rule_orders'];

    public function __construct(
        private Page $page,
    ) {}

    /** M3's list. */
    public function index(Request $request, SegmentPages $pages, ListSegmentsHandler $segments): Response
    {
        return $this->page->render('Promotions/Admin/Segments/Index', $pages->list(
            self::query($request, 'store'),
            self::query($request, 'before_at'),
            self::query($request, 'before_id'),
            $segments,
        )->toArray(), self::WORDS);
    }

    /** M3's page of one segment. */
    public function show(
        Request $request,
        string $segment,
        SegmentPages $pages,
        ViewSegmentHandler $view,
        ListSegmentMembersHandler $members,
        SearchSegmentCandidatesHandler $candidates,
    ): Response {
        $search = self::query($request, 'search');
        $length = $search === null ? 0 : mb_strlen($search);

        return $this->page->render('Promotions/Admin/Segments/Show', $pages->segment(
            $segment,
            self::query($request, 'after_member'),
            // As the box sends it: 2 to 100 characters; anything else searches nothing.
            $length >= 2 && $length <= 100 ? $search : null,
            $view,
            $members,
            $candidates,
        )->toArray(), self::WORDS);
    }

    /** New Segment…, in the store the dialog names; its page opens. */
    public function store(Request $request, StoreChoices $choices, CreateSegmentHandler $handler): RedirectResponse
    {
        $id = null;

        $answer = self::act($request, function () use ($request, $choices, $handler, &$id): void {
            $store = $choices->chosen(self::text($request, 'store'), PromotionsPermissions::SEGMENT_MANAGE) ?? throw new Unauthorized(PromotionsPermissions::SEGMENT_MANAGE);
            [$rule, $months, $orders] = self::rule($request);
            $id = $handler->handle(new CreateSegment(
                $store->id,
                self::text($request, 'name_ar') ?? '',
                self::text($request, 'name_en') ?? '',
                strtoupper(self::text($request, 'segment_type') ?? ''),
                strtoupper(self::text($request, 'audience') ?? ''),
                $rule,
                $months,
                $orders,
            ));
        }, 'promotions::admin_segments.toast.created');

        return is_string($id) ? redirect(self::LIST.'/'.$id)->with('status', __('promotions::admin_segments.toast.created')) : $answer;
    }

    /** Edit…: the names, and a rule's numbers. */
    public function update(Request $request, string $segment, ChangeSegmentHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($request, $segment, $handler): void {
            [$rule, $months, $orders] = self::rule($request);
            $handler->handle(new ChangeSegment($segment, self::text($request, 'name_ar') ?? '', self::text($request, 'name_en') ?? '', $rule, $months, $orders));
        }, 'promotions::admin_segments.toast.saved');
    }

    /** Refresh Now (a rule segment): a toast with how many it holds now. */
    public function refresh(Request $request, string $segment, RefreshSegmentHandler $handler, ViewSegmentHandler $view): RedirectResponse
    {
        try {
            $handler->handle(new RefreshSegment($segment));
            $count = $view->handle(new ViewSegment($segment))->memberCount;
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __('promotions::admin_segments.toast.refreshed', ['count' => number_format($count)]));
    }

    /** Delete Segment…; back to the list of the segment's own store. */
    public function destroy(Request $request, string $segment, ViewSegmentHandler $view, PlatformApi $platform, DeleteSegmentHandler $handler): RedirectResponse
    {
        try {
            $store = $platform->store(StoreId::fromString($view->handle(new ViewSegment($segment))->storeId));
            $handler->handle(new DeleteSegment($segment));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return redirect(self::LIST.($store === null ? '' : '?store='.rawurlencode($store->code)))->with('status', __('promotions::admin_segments.toast.deleted'));
    }

    /**
     * Save Members (a segment picked by hand, owner's Q8): the customers added and removed on the page,
     * merged by `ChangeSegmentMembers` under the segment's own lock - two managers saving at once each
     * keep their changes, and a member no longer eligible never blocks a save.
     */
    public function members(Request $request, string $segment, ChangeSegmentMembersHandler $handler): RedirectResponse
    {
        return self::act(
            $request,
            fn () => $handler->handle(new ChangeSegmentMembers($segment, self::ids($request, 'add'), self::ids($request, 'remove'))),
            'promotions::admin_segments.toast.members',
        );
    }

    /**
     * A rule's kind and numbers as the dialog sends them; numbers in any digits, none when empty.
     *
     * @return array{0: ?string, 1: ?int, 2: ?int}
     *
     * @throws InvalidPromotionAttribute
     */
    private static function rule(Request $request): array
    {
        $rule = self::text($request, 'rule');

        return [$rule === null ? null : strtoupper($rule), self::whole($request, 'rule_months'), self::whole($request, 'rule_orders')];
    }

    /**
     * @throws InvalidPromotionAttribute
     */
    private static function whole(Request $request, string $key): ?int
    {
        $typed = self::text($request, $key);

        if ($typed === null) {
            return null;
        }

        $digits = LatinDigits::of($typed);

        return ctype_digit($digits) && strlen($digits) <= 6 ? (int) $digits : throw new InvalidPromotionAttribute($key, 'a whole number');
    }

    /**
     * The customer ids a list in the request names, lower case; anything else left out.
     *
     * @return list<string>
     */
    private static function ids(Request $request, string $key): array
    {
        $sent = $request->input($key);

        return array_values(array_unique(array_map(
            static fn (string $id): string => strtolower($id),
            array_filter(is_array($sent) ? $sent : [], static fn (mixed $id): bool => is_string($id) && preg_match('/^[0-9A-Za-z]{26}$/', $id) === 1),
        )));
    }

    /**
     * Does the work and answers with the toast that says it was done, or with the refusal - beside its
     * own box when it names one of the form's.
     *
     * @param  Closure(): mixed  $work
     */
    private static function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (InvalidPromotionAttribute $error) {
            if (in_array($error->attribute, self::FIELDS, true) && ! $request->expectsJson()) {
                return back()->withInput()->withErrors([$error->attribute => FormErrors::message($error)]);
            }

            return FormErrors::back($request, $error);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        return back()->with('status', __($done));
    }

    private static function text(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
