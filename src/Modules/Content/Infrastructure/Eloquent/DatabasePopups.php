<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Model\Popup;
use Modules\Content\Domain\Repository\Popups;
use Modules\Content\Domain\ValueObject\Schedule;
use stdClass;

/** `Popups` in SQL (content.md §5: `content.popups`). */
final readonly class DatabasePopups implements Popups
{
    private const string COLUMNS = 'id, store_id, name, title_ar, title_en, text_ar, text_en, button_ar, button_en, link, delay_seconds, media_id, is_scheduled, starts_at, ends_at, version, updated_by, updated_at';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?Popup
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.popups WHERE id = ?', [strtolower($id)]);

        return $row instanceof stdClass ? self::popup($row) : null;
    }

    public function lock(string $id): ?Popup
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.popups WHERE id = ? FOR UPDATE', [strtolower($id)]);

        return $row instanceof stdClass ? self::popup($row) : null;
    }

    public function scheduledIn(string $storeId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT '.self::COLUMNS.' FROM content.popups WHERE store_id = ? AND is_scheduled ORDER BY starts_at, id', [strtolower($storeId)]);

        return array_map(self::popup(...), $rows);
    }

    public function add(Popup $popup): void
    {
        ForeignKeyRefusals::guard(function () use ($popup): void {
            $this->db->table('content.popups')->insert([
                'id' => $popup->id,
                'store_id' => $popup->storeId,
                'created_by' => $popup->updatedBy,
                'created_at' => Rows::at($popup->updatedAt),
                ...self::written($popup),
            ]);
        });
    }

    public function update(Popup $popup): void
    {
        ForeignKeyRefusals::guard(function () use ($popup): void {
            $this->db->table('content.popups')->where('id', $popup->id)->update(self::written($popup));
        });
    }

    public function delete(string $id): void
    {
        $this->db->table('content.popups')->where('id', strtolower($id))->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private static function written(Popup $popup): array
    {
        return [
            'name' => $popup->name,
            'title_ar' => $popup->titleAr,
            'title_en' => $popup->titleEn,
            'text_ar' => $popup->textAr,
            'text_en' => $popup->textEn,
            'button_ar' => $popup->buttonAr,
            'button_en' => $popup->buttonEn,
            'link' => $popup->link,
            'delay_seconds' => $popup->delaySeconds,
            'media_id' => $popup->mediaId,
            'is_scheduled' => $popup->schedule->isScheduled,
            'starts_at' => Rows::at($popup->schedule->startsAt),
            'ends_at' => Rows::at($popup->schedule->endsAt),
            'version' => $popup->version,
            'updated_by' => $popup->updatedBy,
            'updated_at' => Rows::at($popup->updatedAt),
        ];
    }

    private static function popup(stdClass $row): Popup
    {
        return new Popup(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->name,
            (string) $row->title_ar,
            Rows::text($row->title_en),
            (string) $row->text_ar,
            Rows::text($row->text_en),
            Rows::text($row->button_ar),
            Rows::text($row->button_en),
            Rows::text($row->link),
            (int) $row->delay_seconds,
            Rows::text($row->media_id),
            new Schedule((bool) $row->is_scheduled, Rows::moment($row->starts_at), Rows::moment($row->ends_at)),
            (int) $row->version,
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at) ?? CarbonImmutable::now()->toDateTimeImmutable(),
        );
    }
}
