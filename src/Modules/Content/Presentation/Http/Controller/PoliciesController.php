<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Content\Application\Command\DeletePolicy\DeletePolicy;
use Modules\Content\Application\Command\DeletePolicy\DeletePolicyHandler;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicy;
use Modules\Content\Application\Command\PublishPolicy\PublishPolicyHandler;
use Modules\Content\Application\Command\SavePolicy\SavePolicy;
use Modules\Content\Application\Command\SavePolicy\SavePolicyHandler;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicy;
use Modules\Content\Application\Command\UnpublishPolicy\UnpublishPolicyHandler;
use Modules\Content\Application\Policies\PolicyInput;
use Modules\Content\Application\Query\ContentActions\ContentActionsForReader;
use Modules\Content\Application\Query\Policies\PoliciesHandler;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Domain\Model\PolicyKind;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Presentation\Http\Resource\ContentStoreData;
use Modules\Content\Presentation\Http\Resource\PoliciesPageData;
use Modules\Content\Presentation\Http\Resource\PolicyData;
use Modules\Content\Presentation\Http\Resource\PolicyFormPageData;
use Modules\Content\Presentation\Http\Resource\PolicyRowData;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;

/*
| Content › Policies (flags-2026-11.md S3.7; the owner's answers of 2026-11-20): the list by tab, New Policy and a
| policy's own page, Save Draft / Publish / Save Changes, Unpublish, Delete (never published only). Each change goes
| through its own handler under `content.policy.manage` in the stores it touches. A save changes the stores ticked: a
| shared policy whose words change for some of its stores splits (PolicyWriter), and the saved policy may then be a new
| one - its own page is where the person lands. A plain visit gets the page, Show More the JSON.
*/
final readonly class PoliciesController
{
    public const string LIST = '/admin/content/policies';

    /** @var list<string> the form's boxes, by the names it posts */
    private const array BOXES = [
        'kind', 'stores', 'title_ar', 'title_en', 'slug_ar', 'slug_en', 'body_ar', 'body_en', 'seo_title_ar', 'seo_title_en', 'seo_description_ar', 'seo_description_en', 'share_image_ar', 'share_image_en',
    ];

    /** The page's own word files; Content's errors and the panel's come with every page (ContentScreens). */
    private const array WORDS = ['content::admin_documents', 'content::admin_policies', 'content::policies'];

    public function __construct(
        private ContentScreens $screens,
    ) {}

    public function index(Request $request, PoliciesHandler $policies, PlatformApi $platform): Response|JsonResponse
    {
        $tab = Inputs::text($request, 'tab') === 'draft' ? Publication::Draft : Publication::Published;
        $read = $policies->list($tab, Inputs::text($request, 'after'));
        $names = self::storeNames($platform);
        $rows = array_map(static fn (array $row): PolicyRowData => PolicyRowData::fromRow($row, $names), $read['rows']);
        $next = count($rows) === ScreenReads::PAGE ? $rows[count($rows) - 1]->id : null;

        if ($request->expectsJson()) {
            return new JsonResponse(['rows' => array_map(static fn (PolicyRowData $row): array => $row->toArray(), $rows), 'next' => $next]);
        }

        return $this->screens->render('Content/Admin/Policies/Index', (new PoliciesPageData(
            $tab === Publication::Draft ? 'draft' : 'published',
            $read['counts'],
            $rows,
            $next,
        ))->toArray(), self::WORDS);
    }

    public function create(PoliciesHandler $policies, ContentActionsForReader $actions, PlatformApi $platform): Response
    {
        $policies->mayCreate();

        return $this->form(null, $actions, $platform);
    }

    public function edit(string $policy, PoliciesHandler $policies, ContentActionsForReader $actions, PlatformApi $platform): Response
    {
        return $this->form(PolicyData::fromRead($policies->policy($policy)), $actions, $platform);
    }

    public function store(Request $request, PoliciesHandler $policies): RedirectResponse
    {
        return $this->save($request, null, $policies);
    }

    public function update(Request $request, string $policy, PoliciesHandler $policies): RedirectResponse
    {
        return $this->save($request, $policy, $policies);
    }

    /**
     * Unpublish in the stores ticked (the owner's decisions of 2026-11-20). Some of them: they leave it into a
     * switched-off copy of their own, whose page is where the person lands - the policy left behind may serve no store of
     * theirs. All of them: the policy is a draft again, its page reloaded.
     */
    public function unpublish(Request $request, string $policy, UnpublishPolicyHandler $handler): RedirectResponse
    {
        try {
            $copy = $handler->handle(new UnpublishPolicy($policy, Inputs::version($request), self::stores($request)));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, ['stores']);
        }

        return $copy === null
            ? back()->with('status', __('content::admin_policies.unpublished'))
            : redirect(self::LIST."/{$copy}")->with('status', __('content::admin_policies.turned_off'));
    }

    /**
     * Delete from the stores ticked (the owner's decisions of 2026-11-20): all of them deletes the draft, back to the
     * list; some take those stores off it, and the person lands on its page when it still serves a store of theirs.
     */
    public function delete(Request $request, string $policy, DeletePolicyHandler $handler, PoliciesHandler $policies): RedirectResponse
    {
        try {
            $handler->handle(new DeletePolicy($policy, Inputs::version($request), self::stores($request)));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, ['stores']);
        }

        try {
            $policies->policy($policy);
        } catch (DomainError|Unauthorized) {
            return redirect(self::LIST.'?tab=draft')->with('status', __('content::admin_policies.deleted'));
        }

        return redirect(self::LIST."/{$policy}")->with('status', __('content::admin_policies.deleted_in'));
    }

    /**
     * The stores ticked in Unpublish's or Delete's dialog; none sent is every store the policy serves (the handler's).
     *
     * @return list<string>|null
     */
    private static function stores(Request $request): ?array
    {
        return $request->has('stores') ? Inputs::texts($request, 'stores') : null;
    }

    /**
     * Save Draft, or Publish: the policy saved, then published at the version the save made - both with the tick when it
     * was given (content.md §1.3, P14). A new one whose Publish is refused stays a draft, and its own page says why. A
     * published one's Save Changes is a save alone. A save that split the policy names it in its message.
     */
    private function save(Request $request, ?string $id, PoliciesHandler $policies): RedirectResponse
    {
        $input = self::input($request, $id);

        try {
            $saved = app(SavePolicyHandler::class)->handle(new SavePolicy($input));
        } catch (DomainError $error) {
            return Refusals::back($request, $error, self::BOXES);
        }

        $read = PolicyData::fromRead($policies->policy($saved));
        $split = $id !== null && $saved !== strtolower($id);

        if (Inputs::text($request, 'intent') === 'publish' && $read->status === Publication::Draft->value) {
            try {
                app(PublishPolicyHandler::class)->handle(new PublishPolicy($saved, $read->version, $input->read));
            } catch (DomainError $error) {
                return Refusals::to(self::LIST."/{$saved}", $error, self::BOXES);
            }

            return redirect(self::LIST)->with('status', __('content::admin_policies.published'));
        }

        // A split lands on the new copy's own page: that is the policy the person changed (the builder's pick).
        if ($split) {
            return redirect(self::LIST."/{$saved}")->with('status', __('content::admin_policies.split_saved'));
        }

        return redirect(self::LIST.($read->status === Publication::Draft->value ? '?tab=draft' : ''))->with('status', __('content::admin_policies.saved'));
    }

    private function form(?PolicyData $policy, ContentActionsForReader $actions, PlatformApi $platform): Response
    {
        $offered = $this->screens->stores(PoliciesHandler::PERMISSION);
        $offeredIds = array_map(static fn (ContentStoreData $store): string => $store->id, $offered);
        $names = self::storeNames($platform);
        // The stores it serves that are not the person's to change: named, read-only (owner, 2026-11-20).
        $others = $policy === null ? [] : array_values(array_map(static fn (string $id): string => $names[$id] ?? $id, array_diff($policy->stores, $offeredIds)));

        return $this->screens->render('Content/Admin/Policies/Form', (new PolicyFormPageData(
            $offered,
            $others,
            $policy,
            $policy === null ? [] : $this->screens->images($policy->mediaIds()),
            array_map(static fn (PolicyKind $kind): string => $kind->value, PolicyKind::cases()),
            rtrim(url('/'), '/'),
            $actions->translationReady(),
        ))->toArray(), self::WORDS);
    }

    /**
     * Every store's name in the page's language, by id: a policy may serve stores the person may not open.
     *
     * @return array<string, string>
     */
    private static function storeNames(PlatformApi $platform): array
    {
        $names = [];

        foreach ($platform->allStores() as $store) {
            $names[strtolower($store->id)] = $store->name->in(app()->getLocale());
        }

        return $names;
    }

    /** What the form sent, as the writer takes it: a value of the wrong type is no value, for the writer to refuse. */
    private static function input(Request $request, ?string $id): PolicyInput
    {
        $refs = static function (string $key) use ($request): array {
            $images = [];

            foreach (Inputs::map($request, $key) as $ref => $media) {
                if ($media !== null && preg_match('/\A[0-9]{1,6}\z/', (string) $ref) === 1) {
                    $images[(int) $ref] = $media;
                }
            }

            return $images;
        };

        return new PolicyInput(
            $id,
            Inputs::text($request, 'kind'),
            Inputs::texts($request, 'stores'),
            Inputs::version($request),
            Inputs::typed($request, 'title_ar'),
            Inputs::typed($request, 'title_en'),
            Inputs::typed($request, 'slug_ar'),
            Inputs::typed($request, 'slug_en'),
            Inputs::typed($request, 'body_ar'),
            Inputs::typed($request, 'body_en'),
            $refs('images_ar'),
            $refs('images_en'),
            Inputs::typed($request, 'seo_title_ar'),
            Inputs::typed($request, 'seo_title_en'),
            Inputs::typed($request, 'seo_description_ar'),
            Inputs::typed($request, 'seo_description_en'),
            Inputs::text($request, 'share_ar'),
            Inputs::text($request, 'share_en'),
            Inputs::flag($request, 'translated'),
            Inputs::flag($request, 'read'),
        );
    }
}
