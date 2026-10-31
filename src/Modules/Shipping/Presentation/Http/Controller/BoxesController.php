<?php

declare(strict_types=1);

namespace Modules\Shipping\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Shipping\Application\Command\ChangeBox\ChangeBox;
use Modules\Shipping\Application\Command\ChangeBox\ChangeBoxHandler;
use Modules\Shipping\Application\Command\CreateBox\CreateBox;
use Modules\Shipping\Application\Command\CreateBox\CreateBoxHandler;
use Modules\Shipping\Application\Command\DeleteBox\DeleteBox;
use Modules\Shipping\Application\Command\DeleteBox\DeleteBoxHandler;
use Modules\Shipping\Application\Command\OrderBoxes\OrderBoxes;
use Modules\Shipping\Application\Command\OrderBoxes\OrderBoxesHandler;
use Modules\Shipping\Application\Command\SetSizePacking\SetSizePacking;
use Modules\Shipping\Application\Command\SetSizePacking\SetSizePackingHandler;
use Modules\Shipping\Application\Command\SwitchBox\SwitchBox;
use Modules\Shipping\Application\Command\SwitchBox\SwitchBoxHandler;
use Modules\Shipping\Application\Query\ListBoxes\ListBoxesHandler;
use Modules\Shipping\Application\Query\ListPackingSizes\ListPackingSizesHandler;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Exception\BoxNameTaken;
use Modules\Shipping\Domain\Exception\InvalidShippingInput;
use Modules\Shipping\Presentation\Http\Resource\BoxPages;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Text\LatinDigits;

/*
| The Boxes screen (shipping.md §4.1 part 3; the owner, 2026-10-31, (a) in all fourteen): the boxes - add,
| edit, on/off, order, delete - and the sizes packed by hand. Every post names its store (`store`, its code)
| and asks step 5's handlers, which authorize again (handoff §19). The padding is Platform's setting, saved
| through Platform's own settings route (B10).
*/
final readonly class BoxesController
{
    /** @var list<string> */
    private const array WORDS = ['shipping::admin_boxes', 'shipping::errors', 'admin', 'catalog::admin.drag', 'catalog::admin.order'];

    /** The dialog's own boxes, where a refusal naming one is said. */
    private const array FIELDS = ['name', 'length_mm', 'width_mm', 'height_mm', 'max_weight_g'];

    public function __construct(
        private Page $page,
    ) {}

    /** X1. */
    public function index(Request $request, BoxPages $pages, ListBoxesHandler $boxes, ListPackingSizesHandler $sizes): Response
    {
        return $this->page->render('Shipping/Admin/Boxes/Index', $pages->page(self::query($request, 'store'), self::filter($request, 'filter'), $boxes, $sizes)->toArray(), self::WORDS);
    }

    /** The hand-packed list's next page (`hand=1`), or the Add Sizes search (B11, B12). */
    public function sizes(Request $request, BoxPages $pages, ListPackingSizesHandler $sizes): JsonResponse
    {
        return response()->json($pages->search(self::query($request, 'store'), $request->boolean('hand'), self::filter($request, 'q'), self::query($request, 'after'), $sizes));
    }

    /** Add Box (B3). */
    public function store(Request $request, StoreChoices $choices, CreateBoxHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($request, $choices, $handler): void {
            $handler->handle(new CreateBox(
                self::storeOf($request, $choices)->id,
                self::text($request, 'name') ?? '',
                self::whole($request, 'length_mm'),
                self::whole($request, 'width_mm'),
                self::whole($request, 'height_mm'),
                self::whole($request, 'max_weight_g'),
                $request->boolean('active', true),
            ));
        }, 'shipping::admin_boxes.toast.added');
    }

    /** Edit Box. */
    public function update(Request $request, string $box, StoreChoices $choices, ChangeBoxHandler $handler): RedirectResponse
    {
        return self::act($request, function () use ($request, $box, $choices, $handler): void {
            $handler->handle(new ChangeBox(
                self::storeOf($request, $choices)->id,
                strtolower($box),
                self::text($request, 'name') ?? '',
                self::whole($request, 'length_mm'),
                self::whole($request, 'width_mm'),
                self::whole($request, 'height_mm'),
                self::whole($request, 'max_weight_g'),
            ));
        }, 'shipping::admin_boxes.toast.saved');
    }

    /** The switch, at once (B7). */
    public function switch(Request $request, string $box, StoreChoices $choices, SwitchBoxHandler $handler): RedirectResponse
    {
        $on = $request->boolean('active');

        return self::act($request, fn () => $handler->handle(new SwitchBox(self::storeOf($request, $choices)->id, strtolower($box), $on)), $on ? 'shipping::admin_boxes.toast.on' : 'shipping::admin_boxes.toast.off');
    }

    /** Dragged, or moved to the top or the bottom (B9). */
    public function order(Request $request, StoreChoices $choices, OrderBoxesHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new OrderBoxes(self::storeOf($request, $choices)->id, self::ids($request, 'ids'))), 'shipping::admin_boxes.toast.ordered');
    }

    /** Delete, on or off (B8). */
    public function destroy(Request $request, string $box, StoreChoices $choices, DeleteBoxHandler $handler): RedirectResponse
    {
        return self::act($request, fn () => $handler->handle(new DeleteBox(self::storeOf($request, $choices)->id, strtolower($box))), 'shipping::admin_boxes.toast.deleted');
    }

    /**
     * Pack by Hand for the ticked sizes, or Pack Automatically for one: one call each, each size on its own (the
     * backend session, 2026-10-31). Those refused - archived meanwhile - are named back (`failed`), the others
     * stay done, and the dialog unticks what was saved.
     */
    public function pack(Request $request, StoreChoices $choices, SetSizePackingHandler $handler): RedirectResponse
    {
        $byHand = $request->boolean('by_hand');

        try {
            $store = self::storeOf($request, $choices);
        } catch (DomainError $error) {
            return FormErrors::back($request, $error);
        }

        $failed = [];
        $first = null;

        foreach (self::ids($request, 'variants') as $variant) {
            try {
                $handler->handle(new SetSizePacking($store->id, $variant, $byHand));
            } catch (DomainError $error) {
                $failed[] = $variant;
                $first ??= $error;
            }
        }

        if ($first !== null) {
            return back()->withErrors(['form' => __('shipping::admin_boxes.search.some_refused', ['count' => count($failed)]).' '.FormErrors::message($first), 'failed' => implode(',', $failed)]);
        }

        return back()->with('status', __($byHand ? 'shipping::admin_boxes.toast.by_hand' : 'shipping::admin_boxes.toast.automatic'));
    }

    /**
     * The store the form names, where the reader may manage boxes - named, never guessed.
     *
     * @throws Unauthorized
     */
    private static function storeOf(Request $request, StoreChoices $choices): StoreDto
    {
        $code = self::text($request, 'store') ?? throw new Unauthorized(ShippingPermissions::BOXES_MANAGE);

        return $choices->chosen($code, ShippingPermissions::BOXES_MANAGE) ?? throw new Unauthorized(ShippingPermissions::BOXES_MANAGE);
    }

    /** A whole number as typed, Latin digits made; anything else is 0, which the handler refuses by its field. */
    private static function whole(Request $request, string $key): int
    {
        $typed = self::text($request, $key);
        $typed = $typed === null ? '' : LatinDigits::of($typed);

        return preg_match('/\A\d{1,9}\z/', $typed) === 1 ? (int) $typed : 0;
    }

    /** @return list<string> */
    private static function ids(Request $request, string $key): array
    {
        $posted = $request->input($key);

        return is_array($posted) ? array_values(array_map(static fn (string $id): string => strtolower(trim($id)), array_filter($posted, 'is_string'))) : [];
    }

    /**
     * Does the work and answers with the toast, or with the refusal - beside its own box when it names one (a
     * name taken, beside the name); otherwise at the top.
     *
     * @param  Closure(): mixed  $work
     */
    private static function act(Request $request, Closure $work, string $done): RedirectResponse
    {
        try {
            $work();
        } catch (InvalidShippingInput $error) {
            if (in_array($error->field, self::FIELDS, true) && ! $request->expectsJson()) {
                return back()->withInput()->withErrors([$error->field => FormErrors::message($error)]);
            }

            return FormErrors::back($request, $error);
        } catch (BoxNameTaken $error) {
            return back()->withInput()->withErrors(['name' => FormErrors::message($error)]);
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

    /** A filter or a search as typed: 2 to 100 characters, else none. */
    private static function filter(Request $request, string $key): ?string
    {
        $value = self::query($request, $key);

        return $value !== null && mb_strlen($value) >= 2 && mb_strlen($value) <= 100 ? $value : null;
    }
}
