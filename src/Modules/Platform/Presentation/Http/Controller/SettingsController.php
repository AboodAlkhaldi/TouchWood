<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use App\Http\PanelStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Query\ListSettings\ListSettingsHandler;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Presentation\Http\Resource\SettingPages;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Shared\Domain\Error\DomainError;

/**
 * The settings screen (frontend.md 3.5, E4).
 *
 * Every declared setting this person may change, grouped by the module that declared it. A row they
 * may not change is not on the screen at all: each setting carries its own permission, so that is
 * one answer per row rather than one for the page.
 *
 * A store setting applies to the store in the panel's header, which is where the store comes from -
 * never from the request. Somebody cannot reach another store's settings by editing a form.
 */
final readonly class SettingsController
{
    /** @var list<string> */
    private const array WORDS = ['platform::admin_settings', 'platform::errors', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private SettingPages $pages,
    ) {}

    /** E4. */
    public function index(ListSettingsHandler $settings): Response
    {
        return $this->page->render(
            'Platform/Admin/Settings/Index',
            $this->pages->list($settings)->toArray(),
            self::WORDS,
        );
    }

    public function update(
        Request $request,
        string $key,
        UpdateSettingHandler $handler,
        InMemorySettingsRegistry $registry,
        PanelStore $panel,
        StoreDirectory $directory,
    ): RedirectResponse {
        $definition = $registry->definition($key);

        try {
            $handler->handle(new UpdateSetting(
                $key,
                // The header's store, never the form's: a per-store setting belongs to the store
                // the person is looking at. An unknown key goes to the handler as it is, and the
                // handler is the one that says so.
                $definition?->scope === SettingScope::Store ? $this->storeCode($panel, $directory) : null,
                $this->value($request, $definition?->type),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['value']);
        }

        return back()->with('status', __('platform::admin_settings.saved'));
    }

    private function storeCode(PanelStore $panel, StoreDirectory $directory): ?string
    {
        $storeId = $panel->id();

        return $storeId === null ? null : $directory->storeById($storeId)?->code;
    }

    /**
     * The posted value, as the type the module declared.
     *
     * A form sends text, and a setting declared as a number must arrive as a number or the
     * registry refuses it - "5" is not 5 to a strict check (InMemorySettingsRegistry). The type is
     * the module's own answer, so reading the value by it cannot disagree with what it will be
     * checked against. An unknown key has no type, and its raw text goes to the handler, which
     * refuses it by name.
     */
    private function value(Request $request, ?SettingType $type): mixed
    {
        $raw = $request->string('value')->toString();

        return match ($type) {
            SettingType::Integer => $request->integer('value'),
            SettingType::Boolean => $request->boolean('value'),
            SettingType::List => $request->array('value'),
            default => $raw,
        };
    }
}
