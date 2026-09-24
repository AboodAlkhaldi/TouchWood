<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Access\Application\Command\UpdateStoreAddressFormat\UpdateStoreAddressFormat;
use Modules\Access\Application\Command\UpdateStoreAddressFormat\UpdateStoreAddressFormatHandler;
use Modules\Access\Application\Query\AddressFormats\AddressFormatDto;
use Modules\Access\Application\Query\AddressFormats\AddressFormatsForStaff;
use Modules\Access\Application\Query\MyAccount\AddressFieldDto;
use Modules\Access\Domain\Model\StoreAddressFormat;
use Modules\Access\Domain\ValueObject\AddressField;
use Modules\Access\Presentation\Http\Request\AddressFormatRequest;
use Modules\Access\Presentation\Http\Resource\AddressFormatField;
use Modules\Access\Presentation\Http\Resource\AddressFormatPage;
use Modules\Access\Presentation\Http\Resource\AddressFormatStore;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\StoreId;

/**
 * The store address format editor (frontend.md §3.7, decided 2026-09-19).
 *
 * **A country's address form is data.** A store that asks for a district today and a postal code
 * tomorrow is a row changed by a staff member, not a deploy - which is why this is built in this
 * stage rather than seeded once and left (access.md §1.9, amendment 41).
 *
 * It is Access's own screen rather than a panel inside Platform's store editor: the format is
 * Access's data, and Platform may never reference Access.
 *
 * A format that changes leaves saved addresses alone. They keep their values and stop being usable
 * for an order until they satisfy the new shape, which the customer fixes on their own addresses
 * page - so nothing here has to rewrite anybody's address.
 */
final readonly class AddressFormatController
{
    /** @var list<string> */
    private const array WORDS = ['access::address_formats', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private AddressFormatsForStaff $formats,
        private PlatformApi $platform,
        private Application $app,
    ) {}

    public function index(Request $request): Response
    {
        $stores = $this->storesForReader();
        $chosen = $this->chosen($request, $stores);
        // Somebody who may change no store's form at all still gets the page, which says so: an
        // empty form is the honest answer, and a 403 would hide why they are seeing nothing.
        $format = $chosen === null
            ? new AddressFormatDto('', false, [], [], '')
            : $this->formats->forStore($chosen);

        return $this->page->render('Access/Admin/AddressFormats/Index', (new AddressFormatPage(
            stores: $stores,
            storeId: $format->storeId,
            exists: $format->exists,
            fields: array_map(static fn (AddressFieldDto $field): AddressFormatField => new AddressFormatField(
                $field->key,
                $field->labelAr,
                $field->labelEn,
                $field->required,
                $field->maxLength,
            ), $format->fields),
            displayTemplate: $format->displayTemplate,
            // The domain's own numbers, so the screen says the rule that is actually enforced.
            maxFields: StoreAddressFormat::FIELDS_MAX,
            maxLength: AddressField::LENGTH_MAX,
        ))->toArray(), self::WORDS);
    }

    /**
     * The whole form at once: fields and template together.
     *
     * They are saved together because they check each other - a template may only name fields the
     * format has - and saving one half would leave a store whose template points at a field that
     * is no longer there.
     */
    public function save(AddressFormatRequest $request, string $storeId, UpdateStoreAddressFormatHandler $handler): RedirectResponse
    {
        try {
            $handler->handle(new UpdateStoreAddressFormat($storeId, $request->fields(), $request->text('display_template')));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['fields', 'display_template']);
        }

        // Back to the same country, named the way the page names it: the editor asks for a store
        // by its code, and a redirect carrying an id would answer 404 (found by running it).
        $code = $this->platform->store(StoreId::fromString($storeId))->code ?? '';

        return redirect()
            ->to('/admin/address-formats?store='.$code)
            ->with('status', __('access::address_formats.saved'));
    }

    /**
     * The stores this person may change, named in the panel's language.
     *
     * Which those are is Access's answer; null means every store, now and for one opened later.
     *
     * @return list<AddressFormatStore>
     */
    private function storesForReader(): array
    {
        $allowed = $this->formats->stores();
        $locale = $this->app->getLocale();
        $mine = [];

        foreach ($this->platform->stores() as $store) {
            $id = $store->storeId()->value;

            if ($allowed === null || in_array($id, $allowed, true)) {
                $mine[$id] = $store;
            }
        }

        $exists = $this->formats->existFor(array_keys($mine));

        return array_map(
            static fn (string $id): AddressFormatStore => new AddressFormatStore(
                $id,
                $mine[$id]->code,
                $mine[$id]->name->in($locale),
                $exists[$id] ?? false,
            ),
            array_keys($mine),
        );
    }

    /**
     * The store being looked at: the one asked for, or the first they may change.
     *
     * A store asked for by name is looked up through Platform first - a name that is no store at
     * all is a 404, not a refusal - and then handed to the read, **which is what refuses somebody
     * who may not change that store's form**. It is never quietly swapped for one they may: an
     * address bar that answers a different question than it was asked is how people come to
     * believe they changed something they did not.
     *
     * @param  list<AddressFormatStore>  $stores
     */
    private function chosen(Request $request, array $stores): ?string
    {
        $asked = $request->query('store');

        if (is_string($asked) && $asked !== '') {
            $store = $this->platform->storeByCode($asked) ?? abort(404);

            return $store->storeId()->value;
        }

        return $stores === [] ? null : $stores[0]->id;
    }
}
