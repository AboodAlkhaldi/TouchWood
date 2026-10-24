<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Feedback\Domain\Model\RejectReason;
use Modules\Feedback\Domain\Repository\RejectReasons;
use stdClass;

/** `RejectReasons` in SQL (feedback.md §2.4 B4, §5). */
final readonly class DatabaseRejectReasons implements RejectReasons
{
    private const string TABLE = 'feedback.reject_reasons';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function all(): array
    {
        return array_values(array_map(
            static fn (stdClass $row): RejectReason => new RejectReason((string) $row->id, (string) $row->text_ar, (string) $row->text_en, (int) $row->position),
            $this->db->table(self::TABLE)->orderBy('position')->orderBy('id')->get(['id', 'text_ar', 'text_en', 'position'])->all(),
        ));
    }

    public function lock(): void
    {
        $this->db->select("SELECT pg_advisory_xact_lock(hashtext('feedback.reject_reasons'))");
    }

    public function add(string $id, string $textAr, string $textEn, DateTimeImmutable $at): int
    {
        $position = (int) $this->db->table(self::TABLE)->max('position') + 1;
        $this->db->table(self::TABLE)->insert([
            'id' => $id, 'text_ar' => $textAr, 'text_en' => $textEn, 'position' => $position, 'created_at' => Moment::of($at), 'updated_at' => Moment::of($at),
        ]);

        return $position;
    }

    public function change(string $id, string $textAr, string $textEn, DateTimeImmutable $at): void
    {
        $this->db->table(self::TABLE)->where('id', $id)->update(['text_ar' => $textAr, 'text_en' => $textEn, 'updated_at' => Moment::of($at)]);
    }

    public function remove(string $id): void
    {
        $position = $this->db->table(self::TABLE)->where('id', $id)->value('position');
        $this->db->table(self::TABLE)->where('id', $id)->delete();

        if ($position !== null) {
            $this->db->update('UPDATE '.self::TABLE.' SET position = position - 1 WHERE position > ?', [(int) $position]);
        }
    }

    public function order(array $ids, DateTimeImmutable $at): void
    {
        foreach ($ids as $index => $id) {
            $this->db->table(self::TABLE)->where('id', $id)->where('position', '<>', $index + 1)->update(['position' => $index + 1, 'updated_at' => Moment::of($at)]);
        }
    }
}
