<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Illuminate\Contracts\Translation\Translator;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * A report's words in its reader's language, from `ops::reports` - every number in Latin digits (owner,
 * 2026-10-06): the figures go into cells as numbers, and the days as `Y-m-d`.
 */
final readonly class ReportWords
{
    /** @param 'ar'|'en' $locale */
    public function __construct(
        private Translator $translator,
        public string $locale,
    ) {}

    /** @param array<string, string> $replace */
    public function get(string $key, array $replace = []): string
    {
        $line = $this->translator->get('ops::reports.'.$key, $replace, $this->locale);

        return is_string($line) ? $line : $key;
    }

    public function column(string $key, ColumnType $type): ReportColumn
    {
        return new ReportColumn($key, $this->get('columns.'.$key), $type);
    }

    /** The store's name; a switched-off one marked (owner, 2026-10-30). */
    public function store(StoreDto $store): string
    {
        $name = $store->name->in($this->locale);

        return $store->isActive ? $name : $this->get('switched_off', ['store' => $name]);
    }

    /** A way money moved (`BANK_TRANSFER`, `ONLINE`, `OTHER`), in words; one Ops does not know, as Sales names it. */
    public function way(string $way): string
    {
        $words = $this->translator->get('ops::reports.ways', [], $this->locale);

        return is_array($words) && is_string($words[$way] ?? null) ? $words[$way] : $way;
    }

    public function yesNo(bool $value): string
    {
        return $this->get($value ? 'yes' : 'no');
    }
}
