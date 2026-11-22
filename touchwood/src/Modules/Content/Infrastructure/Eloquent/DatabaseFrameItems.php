<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Model\Bar;
use Modules\Content\Domain\Model\Footer;
use Modules\Content\Domain\Repository\FrameItems;
use stdClass;

/**
 * `FrameItems` in SQL (content.md §5: `content.bars`, `content.footers`). A store with no row has an empty one; the
 * first save writes it. Locking a row that is not there yet cannot hold anything, so a save first takes an advisory
 * lock named for the store's frame, held until the transaction ends.
 */
final readonly class DatabaseFrameItems implements FrameItems
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function bar(string $storeId, bool $lock = false): Bar
    {
        $storeId = strtolower($storeId);
        $this->holdStore($storeId, $lock);
        $row = $this->db->selectOne('SELECT * FROM content.bars WHERE store_id = ?'.($lock ? ' FOR UPDATE' : ''), [$storeId]);

        if (! $row instanceof stdClass) {
            return Bar::none($storeId);
        }

        return new Bar(
            $storeId,
            Rows::text($row->text_ar),
            Rows::text($row->text_en),
            Rows::text($row->link),
            (bool) $row->is_on,
            Rows::moment($row->starts_at),
            Rows::moment($row->ends_at),
            (int) $row->version,
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at),
        );
    }

    public function saveBar(Bar $bar): void
    {
        $this->db->table('content.bars')->updateOrInsert(['store_id' => $bar->storeId], [
            'text_ar' => $bar->textAr,
            'text_en' => $bar->textEn,
            'link' => $bar->link,
            'is_on' => $bar->isOn,
            'starts_at' => Rows::at($bar->startsAt),
            'ends_at' => Rows::at($bar->endsAt),
            'version' => $bar->version,
            'updated_by' => $bar->updatedBy,
            'updated_at' => Rows::at($bar->updatedAt),
        ]);
    }

    public function footer(string $storeId, bool $lock = false): Footer
    {
        $storeId = strtolower($storeId);
        $this->holdStore($storeId, $lock);
        $row = $this->db->selectOne('SELECT * FROM content.footers WHERE store_id = ?'.($lock ? ' FOR UPDATE' : ''), [$storeId]);

        if (! $row instanceof stdClass) {
            return Footer::none($storeId);
        }

        /** @var list<array{title: array{ar: string, en: string}, links: list<array{text: array{ar: string, en: string}, link: string}>}> $columns */
        $columns = Rows::decode($row->columns);

        return new Footer(
            $storeId,
            Rows::text($row->about_ar),
            Rows::text($row->about_en),
            Rows::text($row->phone),
            Rows::text($row->email),
            Rows::text($row->address_ar),
            Rows::text($row->address_en),
            Rows::text($row->copyright_ar),
            Rows::text($row->copyright_en),
            $columns,
            (int) $row->version,
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at),
        );
    }

    public function saveFooter(Footer $footer): void
    {
        $this->db->table('content.footers')->updateOrInsert(['store_id' => $footer->storeId], [
            'about_ar' => $footer->aboutAr,
            'about_en' => $footer->aboutEn,
            'phone' => $footer->phone,
            'email' => $footer->email,
            'address_ar' => $footer->addressAr,
            'address_en' => $footer->addressEn,
            'copyright_ar' => $footer->copyrightAr,
            'copyright_en' => $footer->copyrightEn,
            'columns' => Rows::json($footer->columns),
            'version' => $footer->version,
            'updated_by' => $footer->updatedBy,
            'updated_at' => Rows::at($footer->updatedAt),
        ]);
    }

    /** Two first saves of one store's row wait on each other here, so the second sees the first's version. */
    private function holdStore(string $storeId, bool $lock): void
    {
        if ($lock) {
            $this->db->select('SELECT pg_advisory_xact_lock(hashtext(?))', ["content:frame:{$storeId}"]);
        }
    }
}
