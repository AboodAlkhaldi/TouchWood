<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Domain\Model\BroadcastStatus;
use Modules\Ops\Domain\Model\Notice;
use Modules\Ops\Domain\Repository\Notices;
use Modules\Ops\Domain\ValueObject\WrittenText;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/** `Notices` in SQL (ops.md §5: `ops.notices`). */
final readonly class DatabaseNotices implements Notices
{
    private const string COLUMNS = 'id, store_id, title_ar, title_en, body_ar, body_en, link, by_bell, by_email, status, send_at, created_by, created_at, updated_at, started_at, bell_count, email_count, failed_count, segment_id';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(Notice $notice): void
    {
        $this->db->table('ops.notices')->insert([
            'id' => $notice->id,
            'store_id' => $notice->store->value,
            ...self::written($notice),
            'created_by' => $notice->createdBy,
            'created_at' => Moment::of($notice->createdAt),
        ]);
    }

    public function find(string $id): ?Notice
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM ops.notices WHERE id = ?', [strtolower($id)]);

        return $row instanceof stdClass ? self::notice($row) : null;
    }

    public function lock(string $id): ?Notice
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM ops.notices WHERE id = ? FOR UPDATE', [strtolower($id)]);

        return $row instanceof stdClass ? self::notice($row) : null;
    }

    public function update(Notice $notice): void
    {
        $this->db->table('ops.notices')->where('id', $notice->id)->update(self::written($notice));
    }

    public function page(StoreId $store, ?string $afterId, int $limit): array
    {
        $bindings = [$store->value];
        $after = '';

        if ($afterId !== null) {
            // The cursor is one of the store's own notices; anything else ends the list.
            $cursor = $this->db->selectOne('SELECT created_at, id FROM ops.notices WHERE id = ? AND store_id = ?', [strtolower($afterId), $store->value]);

            if (! $cursor instanceof stdClass) {
                return [];
            }

            $after = ' AND (created_at, id) < (?::timestamptz, ?)';
            $bindings = [...$bindings, (string) $cursor->created_at, (string) $cursor->id];
        }

        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT '.self::COLUMNS." FROM ops.notices WHERE store_id = ?{$after} ORDER BY created_at DESC, id DESC LIMIT ?", [...$bindings, $limit]);

        return array_map(self::notice(...), $rows);
    }

    public function startDue(DateTimeImmutable $now): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            "UPDATE ops.notices SET status = 'SENDING', started_at = ?::timestamptz WHERE status = 'SCHEDULED' AND send_at <= ?::timestamptz RETURNING id",
            [Moment::of($now), Moment::of($now)],
        );

        return array_map(static fn (stdClass $row): string => (string) $row->id, $rows);
    }

    /** @return array<string, mixed> */
    private static function written(Notice $notice): array
    {
        return [
            'title_ar' => $notice->text->titleAr,
            'title_en' => $notice->text->titleEn,
            'body_ar' => $notice->text->bodyAr,
            'body_en' => $notice->text->bodyEn,
            'link' => $notice->text->link,
            'by_bell' => $notice->byBell,
            'by_email' => $notice->byEmail,
            'status' => $notice->status->value,
            'send_at' => Moment::of($notice->sendAt),
            'updated_at' => Moment::of($notice->updatedAt),
            'started_at' => $notice->startedAt === null ? null : Moment::of($notice->startedAt),
            'segment_id' => $notice->segmentId,
        ];
    }

    private static function notice(stdClass $row): Notice
    {
        return new Notice(
            (string) $row->id,
            StoreId::fromString((string) $row->store_id),
            WrittenText::of((string) $row->title_ar, (string) $row->title_en, (string) $row->body_ar, (string) $row->body_en, $row->link === null ? null : (string) $row->link),
            (bool) $row->by_bell,
            (bool) $row->by_email,
            BroadcastStatus::from((string) $row->status),
            self::at($row->send_at),
            (string) $row->created_by,
            self::at($row->created_at),
            self::at($row->updated_at),
            $row->started_at === null ? null : self::at($row->started_at),
            (int) $row->bell_count,
            (int) $row->email_count,
            (int) $row->failed_count,
            $row->segment_id === null ? null : (string) $row->segment_id,
        );
    }

    private static function at(mixed $value): DateTimeImmutable
    {
        return CarbonImmutable::parse((string) $value)->toDateTimeImmutable();
    }
}
