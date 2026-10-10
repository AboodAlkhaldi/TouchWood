<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\FormErrors;
use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Application\Query\ListSettings\ListSettingsHandler;
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
 * A store setting applies to the store chosen in the page's own filter (platform.md §9.10; the owner,
 * 2026-10-06 - the panel has no store worked in). The store comes with the request, so nothing is
 * taken on trust: the page offers only the stores the person may change, and the handler asserts the
 * setting's permission in the store sent and refuses an off one to anyone but a Super Admin.
 */
final readonly class SettingsController
{
    /** @var list<string> */
    private const array WORDS = ['platform::admin_settings', 'platform::errors', 'access::errors', 'admin'];

    public function __construct(
        private Page $page,
        private SettingPages $pages,
    ) {}

    /** E4, for the store in the address (`?store=sa`), or the first the person may change. */
    public function index(Request $request, ListSettingsHandler $settings): Response
    {
        return $this->page->render(
            'Platform/Admin/Settings/Index',
            $this->pages->list($settings, self::query($request, 'store'))->toArray(),
            self::WORDS,
        );
    }

    public function update(
        Request $request,
        string $key,
        UpdateSettingHandler $handler,
        InMemorySettingsRegistry $registry,
    ): RedirectResponse {
        $definition = $registry->definition($key);

        try {
            $handler->handle(new UpdateSetting(
                $key,
                // The store the page shows, sent with the save: a per-store setting belongs to it. A
                // global one takes none. An unknown key goes to the handler as it is, and the
                // handler is the one that says so.
                $definition?->scope === SettingScope::Store ? self::query($request, 'store') : null,
                $this->value($request, $definition?->type),
            ));
        } catch (DomainError $error) {
            return FormErrors::back($request, $error, ['value']);
        }

        return back()->with('status', __('platform::admin_settings.saved'));
    }

    /** A text field of the request, trimmed; null when absent, empty, or not text (`?store[]=`). */
    private static function query(Request $request, string $field): ?string
    {
        $value = $request->input($field);
        $value = is_string($value) ? trim($value) : '';

        return $value === '' ? null : $value;
    }

    /**
     * The posted value, as the type the module declared.
     *
     * A form sends text, and a setting declared as a number must arrive as a number or the
     * registry refuses it - "5" is not 5 to a strict check (InMemorySettingsRegistry). The type is
     * the module's own answer, so reading the value by it cannot disagree with what it will be
     * checked against. An unknown key has no type, and its raw text goes to the handler, which
     * refuses it by name.
     *
     * Only text that **is** a number or a yes/no becomes one: anything else reaches the registry as
     * it came, and its strict type check refuses it with the setting's own message. Laravel's
     * `integer()` and `boolean()` would turn an emptied field into 0 and any text into false, so
     * clearing "the most of an order points may pay" saved 0 and switched redemption off while the
     * page said "Saved" (Loyalty step 1's review, owner 2026-10-10).
     */
    private function value(Request $request, ?SettingType $type): mixed
    {
        $raw = $request->string('value')->toString();

        return match ($type) {
            SettingType::Integer => self::wholeNumber($request->input('value')),
            SettingType::Boolean => self::yesOrNo($request->input('value')),
            SettingType::List => $request->array('value'),
            default => $raw,
        };
    }

    /** A whole number written as one ("12", "-3"), as a number; anything else as it came. */
    private static function wholeNumber(mixed $value): mixed
    {
        if (is_string($value) && preg_match('/\A-?\d{1,18}\z/', trim($value)) === 1) {
            return (int) trim($value);
        }

        return $value;
    }

    /** "true" or "false" (what the page's switch sends), "1" or "0", as a yes/no; anything else as it came. */
    private static function yesOrNo(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return match (strtolower(trim($value))) {
            'true', '1' => true,
            'false', '0' => false,
            default => $value,
        };
    }
}
