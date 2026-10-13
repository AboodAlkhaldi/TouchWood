<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Domain\Model\WordPair;
use Modules\Catalog\Domain\Repository\WordPairRepository;
use Shared\Infrastructure\Persistence\Ulids;
use stdClass;

final readonly class DatabaseWordPairRepository implements WordPairRepository
{
    private const string TABLE = 'catalog.word_pairs';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function find(string $pairId): ?WordPair
    {
        if (! Ulids::valid($pairId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($pairId))->first();

        return $row instanceof stdClass ? self::toPair($row) : null;
    }

    public function exists(WordPair $pair): bool
    {
        return $this->db->table(self::TABLE)->where('word_a', $pair->wordA)->where('word_b', $pair->wordB)->exists();
    }

    public function add(WordPair $pair): void
    {
        $this->db->table(self::TABLE)->insert(['id' => $pair->id, 'word_a' => $pair->wordA, 'word_b' => $pair->wordB, 'created_at' => CarbonImmutable::now()]);
    }

    public function delete(string $pairId): void
    {
        $this->db->table(self::TABLE)->where('id', strtolower($pairId))->delete();
    }

    public function all(): array
    {
        return array_values(array_map(
            static fn (stdClass $row): WordPair => self::toPair($row),
            $this->db->table(self::TABLE)->orderBy('word_a')->orderBy('word_b')->get()->all(),
        ));
    }

    private static function toPair(stdClass $row): WordPair
    {
        return WordPair::reconstitute((string) $row->id, (string) $row->word_a, (string) $row->word_b);
    }
}
