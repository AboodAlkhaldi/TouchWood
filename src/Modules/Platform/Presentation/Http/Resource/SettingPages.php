<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use App\Http\PanelStore;
use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Application\Query\ListSettings\ListSettings;
use Modules\Platform\Application\Query\ListSettings\ListSettingsHandler;
use Modules\Platform\Application\Query\ListSettings\SettingRow;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;

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
        private PanelStore $panel,
        private StoreDirectory $directory,
        private InMemorySettingsRegistry $registry,
    ) {}

    /** E4. */
    public function list(ListSettingsHandler $handler): SettingsPage
    {
        $storeId = $this->panel->id();
        $rows = $handler->handle(new ListSettings($storeId));

        /** @var array<string, list<SettingRowData>> $byModule */
        $byModule = [];

        foreach ($rows as $row) {
            $byModule[$row->module][] = $this->row($row);
        }

        $groups = [];

        foreach ($byModule as $module => $settings) {
            $groups[] = new SettingGroup($module, $this->moduleName($module), $settings);
        }

        $store = $storeId === null ? null : $this->directory->storeById($storeId);

        return new SettingsPage($groups, $store?->name->in($this->locale()));
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
