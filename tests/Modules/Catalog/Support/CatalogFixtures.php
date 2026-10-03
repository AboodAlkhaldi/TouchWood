<?php

declare(strict_types=1);

namespace Tests\Modules\Catalog\Support;

use ArrayObject;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

/**
 * What Catalog's tests need around them — kept here, not as global functions, so two test files
 * never declare the same name (lesson 43).
 */
final class CatalogFixtures
{
    /**
     * A staff member holding these Catalog jobs in these stores ('*' for All stores), acting from now.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $stores
     */
    public static function actAsStaffWith(array $permissions, array $stores = ['*']): string
    {
        $staffId = Fx::staffWith($permissions, $stores);
        Fx::actAsStaff($staffId);

        return $staffId;
    }

    /**
     * A media row as Platform writes one — every column, timestamps included (lesson 123).
     */
    public static function media(string $visibility = 'PUBLIC', string $mime = 'image/jpeg'): string
    {
        $id = strtolower((string) Str::ulid());
        $now = CarbonImmutable::now();
        $image = str_starts_with($mime, 'image/');

        DB::table('platform.media')->insert([
            'id' => $id,
            'visibility' => $visibility,
            'disk' => 'local',
            'object_key' => "media/{$id}.".($image ? 'jpg' : 'pdf'),
            'original_filename' => $image ? 'photo.jpg' : 'paper.pdf',
            'mime' => $mime,
            'bytes' => 1000,
            'width' => $image ? 10 : null,
            'height' => $image ? 10 : null,
            'checksum' => hash('sha256', $id),
            'variants_status' => $visibility === 'PUBLIC' && $image ? 'READY' : null,
            'variants_queued_at' => $visibility === 'PUBLIC' && $image ? $now : null,
            'variants_generated_at' => $visibility === 'PUBLIC' && $image ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * Every advisory lock taken from now on, with the transaction level it was taken at (lesson 110):
     * a list's lock must be taken inside its change's own transaction.
     *
     * @return ArrayObject<int, array{key: string, level: int}>
     */
    public static function recordLocks(): ArrayObject
    {
        /** @var ArrayObject<int, array{key: string, level: int}> $locks */
        $locks = new ArrayObject;

        DB::listen(function (QueryExecuted $query) use ($locks): void {
            if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
                $locks[] = ['key' => (string) ($query->bindings[0] ?? ''), 'level' => DB::transactionLevel()];
            }
        });

        return $locks;
    }
}
