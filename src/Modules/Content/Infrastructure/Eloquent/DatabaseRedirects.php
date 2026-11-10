<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Model\Redirect;
use Modules\Content\Domain\Repository\Redirects;
use stdClass;

/**
 * `Redirects` in SQL (content.md §5: `content.redirects`). The old address is the key, compared byte for byte
 * (`COLLATE "C"`), so a visitor's answer is one read by the key. W11's list pages by most visited, then old address.
 */
final readonly class DatabaseRedirects implements Redirects
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $oldPath, bool $lock = false): ?Redirect
    {
        $row = $this->db->selectOne('SELECT * FROM content.redirects WHERE old_path = ?'.($lock ? ' FOR UPDATE' : ''), [$oldPath]);

        return $row instanceof stdClass ? self::line($row) : null;
    }

    public function findMany(array $oldPaths, bool $lock = false): array
    {
        if ($oldPaths === []) {
            return [];
        }

        $found = [];

        // A sheet holds 5,000 lines at most: in pages, well inside PostgreSQL's limit on bindings.
        foreach (array_chunk(array_values(array_unique($oldPaths)), 1000) as $chunk) {
            $rows = $this->db->select('SELECT * FROM content.redirects WHERE old_path = ANY(?::text[]) ORDER BY old_path'.($lock ? ' FOR UPDATE' : ''), [self::array($chunk)]);

            foreach ($rows as $row) {
                $found[(string) $row->old_path] = self::line($row);
            }
        }

        return $found;
    }

    public function newPathOf(string $oldPath): ?string
    {
        $path = $this->db->table('content.redirects')->where('old_path', $oldPath)->value('new_path');

        return $path === null ? null : (string) $path;
    }

    public function add(Redirect $redirect): void
    {
        $now = Rows::at(Moments::now());

        $this->db->table('content.redirects')->insert([
            ...self::columns($redirect),
            'old_path' => $redirect->oldPath,
            'visits' => $redirect->visits,
            'last_visit_at' => Rows::at($redirect->lastVisitAt),
            'source' => $redirect->source,
            'created_by' => $redirect->createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function update(Redirect $redirect, ?string $was = null): void
    {
        $this->db->table('content.redirects')->where('old_path', $was ?? $redirect->oldPath)->update([
            ...self::columns($redirect),
            'old_path' => $redirect->oldPath,
            'updated_at' => Rows::at(Moments::now()),
        ]);
    }

    public function delete(string $oldPath): void
    {
        $this->db->table('content.redirects')->where('old_path', $oldPath)->delete();
    }

    public function countVisit(string $oldPath, DateTimeImmutable $at): void
    {
        $this->db->update('UPDATE content.redirects SET visits = visits + 1, last_visit_at = ? WHERE old_path = ?', [Rows::at($at), $oldPath]);
    }

    public function resetCount(string $oldPath): void
    {
        $this->db->table('content.redirects')->where('old_path', $oldPath)->update(['visits' => 0, 'last_visit_at' => null, 'updated_at' => Rows::at(Moments::now())]);
    }

    public function mark(string $oldPath, string $newPath, string $result, ?string $finalPath, DateTimeImmutable $at): void
    {
        $this->db->table('content.redirects')->where('old_path', $oldPath)->where('new_path', $newPath)->update([
            'check_result' => $result,
            'check_final_path' => $result === Redirect::FORWARDS ? $finalPath : null,
            'checked_at' => Rows::at($at),
        ]);
    }

    public function page(?string $q, ?string $after, int $limit): array
    {
        $query = $this->db->table('content.redirects')->orderByDesc('visits')->orderBy('old_path')->limit($limit + 1);
        $q = trim((string) $q);

        if ($q !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q).'%';
            $query->where(static fn ($where) => $where->where('old_path', 'ilike', $like)->orWhere('new_path', 'ilike', $like));
        }

        // A cursor is `{visits}:{old path}`, as `next` gives it.
        if ($after !== null && preg_match('/\A(\d{1,18}):(.+)\z/s', $after, $cursor) === 1) {
            $visits = (int) $cursor[1];
            $query->where(static fn ($where) => $where->where('visits', '<', $visits)->orWhere(static fn ($tie) => $tie->where('visits', $visits)->where('old_path', '>', $cursor[2])));
        }

        $rows = array_map(self::line(...), array_values($query->get()->all()));
        $page = array_slice($rows, 0, $limit);
        $last = $page === [] ? null : $page[array_key_last($page)];

        return ['rows' => $page, 'next' => count($rows) > $limit && $last !== null ? "{$last->visits}:{$last->oldPath}" : null];
    }

    public function after(?string $oldPath, int $limit): array
    {
        return array_map(self::line(...), array_values($this->db->table('content.redirects')
            ->when($oldPath !== null, static fn ($query) => $query->where('old_path', '>', $oldPath))
            ->orderBy('old_path')
            ->limit($limit)
            ->get()
            ->all()));
    }

    /**
     * @return array<string, string|null>
     */
    private static function columns(Redirect $redirect): array
    {
        return [
            'new_path' => $redirect->newPath,
            'check_result' => $redirect->checkResult,
            'check_final_path' => $redirect->checkFinalPath,
            'checked_at' => Rows::at($redirect->checkedAt),
        ];
    }

    private static function line(mixed $row): Redirect
    {
        if (! $row instanceof stdClass) {
            throw new \LogicException('A redirect row is a row.');
        }

        return new Redirect(
            (string) $row->old_path,
            (string) $row->new_path,
            (int) $row->visits,
            Rows::moment($row->last_visit_at),
            (string) $row->source,
            Rows::text($row->check_result),
            Rows::text($row->check_final_path),
            Rows::moment($row->checked_at),
            Rows::text($row->created_by),
        );
    }

    /**
     * @param  list<string>  $values
     */
    private static function array(array $values): string
    {
        // A PostgreSQL text array literal: each value quoted, its quotes and backslashes escaped.
        return '{'.implode(',', array_map(static fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"', $values)).'}';
    }
}
