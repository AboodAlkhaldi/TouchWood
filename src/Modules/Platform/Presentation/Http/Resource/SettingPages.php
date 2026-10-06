<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Application\Query\ListSettings\ListSettings;
use Modules\Platform\Application\Query\ListSettings\ListSettingsHandler;
use Modules\Platform\Application\Query\ListSettings\SettingRow;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Application\Settings\InMemorySettingsSectionLines;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Platform\Public\Enums\SettingScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Platform's settings read, in the shape the screen wants (frontend.md 3.5, E4).
 *
 * It decides nothing: which settings appear is the handler's answer, row by row. What happens here
 * is grouping by the module that declared each one, and finding each setting's name in that
 * module's own words.
 */
final readonly class SettingPages
{
    public function __construct(
        private Application $app,
        private StoreChoices $choices,
        private InMemorySettingsRegistry $registry,
        private InMemorySettingsSectionLines $lines,
    ) {}

    /**
     * E4, for the store asked for in the page's own filter (platform.md §9.10) - the first the person
     * may change a store's setting in when none is asked.
     *
     * @throws Unauthorized when another store is asked for
     */
    public function list(ListSettingsHandler $handler, ?string $storeCode): SettingsPage
    {
        $permissions = $this->storeSettingPermissions();
        $offered = $permissions === [] ? [] : $this->choices->forJobs(...$permissions);
        $chosen = $permissions === [] ? null : $this->choices->chosen($storeCode, ...$permissions);
        $storeId = $chosen?->id;
        $rows = $handler->handle(new ListSettings($storeId));

        /** @var array<string, list<SettingRowData>> $byModule */
        $byModule = [];

        foreach ($rows as $row) {
            $byModule[$row->module][] = $this->row($row);
        }

        $groups = [];
        $store = $storeId === null ? null : StoreId::fromString($storeId);

        // A section is here only when the reader sees one of its settings, so its line is too.
        foreach ($byModule as $module => $settings) {
            $groups[] = new SettingGroup($module, $this->moduleName($module), $this->lines->for($module)?->line($store), $settings);
        }

        return new SettingsPage(
            $groups,
            $chosen?->name->in($this->locale()),
            $chosen?->code,
            array_map(fn (StoreDto $store): StoreOption => new StoreOption($store->code, $store->name->in($this->locale()), $store->isActive), $offered),
            $chosen?->timezone,
        );
    }

    /**
     * The permissions of the settings that belong to one store: holding any of them in a store
     * puts that store in the filter.
     *
     * @return list<string>
     */
    private function storeSettingPermissions(): array
    {
        $permissions = [];

        foreach ($this->registry->all() as $definition) {
            if ($definition->scope === SettingScope::Store) {
                $permissions[$definition->permission] = true;
            }
        }

        return array_keys($permissions);
    }

    private function row(SettingRow $row): SettingRowData
    {
        $definition = $this->registry->definition($row->key);

        return new SettingRowData(
            $row->key,
            // The module's own word for it. A setting nobody has written down yet shows its key,
            // which is ugly and readable - and never a blank line where a name should be.
            $definition === null ? $row->key : $this->text($definition->labelKey(), $row->key),
            $row->scope->value,
            $row->type->value,
            $row->value,
            $row->default,
            $row->isDefault,
            $row->sensitive,
            $row->min,
            $row->max,
        );
    }

    /**
     * A module's name for its section heading, from its own words.
     */
    private function moduleName(string $module): string
    {
        return $this->text("{$module}::settings.module", ucfirst($module));
    }

    private function text(string $key, string $fallback): string
    {
        $translated = __($key, [], $this->locale());

        return is_string($translated) && $translated !== $key ? $translated : $fallback;
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
