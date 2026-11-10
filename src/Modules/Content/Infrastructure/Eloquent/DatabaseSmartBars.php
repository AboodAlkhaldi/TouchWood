<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Model\SmartBar;
use Modules\Content\Domain\Model\SmartBarScope;
use Modules\Content\Domain\Repository\SmartBars;
use stdClass;

/** `SmartBars` in SQL (content.md §5: `content.smart_bars`). */
final readonly class DatabaseSmartBars implements SmartBars
{
    private const string COLUMNS = 'id, store_id, scope, category_id, run_id, message_ar, message_en, link, starts_at, ends_at';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?SmartBar
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.smart_bars WHERE id = ?', [strtolower($id)]);

        return $row instanceof stdClass ? self::bar($row) : null;
    }

    public function lock(string $id): ?SmartBar
    {
        $row = $this->db->selectOne('SELECT '.self::COLUMNS.' FROM content.smart_bars WHERE id = ? FOR UPDATE', [strtolower($id)]);

        return $row instanceof stdClass ? self::bar($row) : null;
    }

    public function add(SmartBar $bar, ?string $by): void
    {
        ForeignKeyRefusals::guard(function () use ($bar, $by): void {
            $now = Rows::at(CarbonImmutable::now()->toDateTimeImmutable());
            $this->db->table('content.smart_bars')->insert([
                'id' => $bar->id,
                'store_id' => $bar->storeId,
                'created_by' => $by,
                'created_at' => $now,
                ...self::written($bar, $by),
            ]);
        });
    }

    public function update(SmartBar $bar, ?string $by): void
    {
        ForeignKeyRefusals::guard(function () use ($bar, $by): void {
            $this->db->table('content.smart_bars')->where('id', $bar->id)->update(self::written($bar, $by));
        });
    }

    public function delete(string $id): void
    {
        $this->db->table('content.smart_bars')->where('id', strtolower($id))->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private static function written(SmartBar $bar, ?string $by): array
    {
        return [
            'scope' => $bar->scope->value,
            'category_id' => $bar->categoryId,
            'run_id' => $bar->runId,
            'message_ar' => $bar->messageAr,
            'message_en' => $bar->messageEn,
            'link' => $bar->link,
            'starts_at' => Rows::at($bar->startsAt),
            'ends_at' => Rows::at($bar->endsAt),
            'updated_by' => $by,
            'updated_at' => Rows::at(CarbonImmutable::now()->toDateTimeImmutable()),
        ];
    }

    private static function bar(stdClass $row): SmartBar
    {
        return new SmartBar(
            (string) $row->id,
            (string) $row->store_id,
            SmartBarScope::from((string) $row->scope),
            Rows::text($row->category_id),
            Rows::text($row->run_id),
            (string) $row->message_ar,
            (string) $row->message_en,
            Rows::text($row->link),
            Rows::moment($row->starts_at),
            Rows::moment($row->ends_at),
        );
    }
}
