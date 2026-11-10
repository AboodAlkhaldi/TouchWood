<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Repository\RedirectUploads;
use stdClass;

/** `RedirectUploads` in SQL (content.md §5: `content.redirect_uploads`), the lines in one jsonb array. */
final readonly class DatabaseRedirectUploads implements RedirectUploads
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(string $id, array $lines, ?string $by, DateTimeImmutable $at): void
    {
        $this->db->table('content.redirect_uploads')->insert([
            'id' => $id,
            'lines' => Rows::json($lines),
            'state' => 'CHECKED',
            'created_by' => $by,
            'created_at' => Rows::at($at),
        ]);
    }

    public function find(string $id, DateTimeImmutable $since, bool $lock = false): ?array
    {
        $row = $this->db->selectOne('SELECT * FROM content.redirect_uploads WHERE id = ? AND created_at >= ?'.($lock ? ' FOR UPDATE' : ''), [$id, Rows::at($since)]);

        if (! $row instanceof stdClass) {
            return null;
        }

        /** @var list<array{line: int, old: string, new: string, result: string, reason: string|null, typed_old: string, typed_new: string}> $lines */
        $lines = Rows::decode($row->lines);

        return [
            'lines' => $lines,
            'state' => (string) $row->state,
            'created_by' => Rows::text($row->created_by),
            'created_at' => Rows::moment($row->created_at) ?? throw new \LogicException('A sheet has its moment.'),
        ];
    }

    public function applied(string $id, DateTimeImmutable $at): void
    {
        $this->db->table('content.redirect_uploads')->where('id', $id)->update(['state' => 'APPLIED', 'applied_at' => Rows::at($at)]);
    }

    public function pruneBefore(DateTimeImmutable $before): void
    {
        $this->db->table('content.redirect_uploads')->where('created_at', '<', Rows::at($before))->delete();
    }
}
