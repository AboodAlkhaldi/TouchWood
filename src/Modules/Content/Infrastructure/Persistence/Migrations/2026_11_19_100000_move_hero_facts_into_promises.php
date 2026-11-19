<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentCache;

/*
| flags-2026-11.md S1.7 (owner, 2026-11-18; pick F6): the hero's facts became promises, so the promises row has one
| look. Each store's saved facts move into its PROMISES section, after the promises it has, up to 5: each becomes a
| promise with the icon `award`, its figure (or, with none, its caption) as the title and the caption as the line
| when there was a figure. A store with no promises section gets one, hidden, after its last section, for staff to
| show. Facts past the fifth are dropped and named in the log. The hero keeps its title only.
*/

return new class extends Migration
{
    private const int MOST = 5;

    public function up(): void
    {
        foreach (DB::table('content.home_sections')->where('type', 'HERO')->get(['id', 'store_id', 'settings']) as $hero) {
            /** @var array<string, mixed> $settings */
            $settings = json_decode((string) $hero->settings, true) ?: [];
            $facts = is_array($settings['facts'] ?? null) ? $settings['facts'] : [];
            unset($settings['facts']);
            DB::table('content.home_sections')->where('id', $hero->id)->update(['settings' => json_encode((object) $settings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);

            $promises = array_values(array_filter(array_map(self::promise(...), $facts)));

            if ($promises === []) {
                continue;
            }

            $row = DB::table('content.home_sections')->where('store_id', $hero->store_id)->where('type', 'PROMISES')->first(['id', 'settings']);
            /** @var array<string, mixed> $kept */
            $kept = $row === null ? [] : (json_decode((string) $row->settings, true) ?: []);
            $existing = is_array($kept['promises'] ?? null) ? array_values($kept['promises']) : [];
            $all = [...$existing, ...$promises];
            $kept['promises'] = array_slice($all, 0, self::MOST);

            if (count($all) > self::MOST) {
                $dropped = array_slice($all, self::MOST);
                Log::notice('Content: hero facts past the fifth promise were dropped (flags-2026-11.md S1.7).', ['store' => $hero->store_id, 'dropped' => $dropped]);
                // Listed in the migration's output too (F6), outside the tests.
                if (! app()->runningUnitTests()) {
                    fwrite(STDOUT, "  Store {$hero->store_id}: facts past the fifth promise dropped: ".json_encode($dropped, JSON_UNESCAPED_UNICODE).PHP_EOL);
                }
            }

            $json = json_encode((object) $kept, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

            if ($row !== null) {
                // A new version: a form opened before is refused rather than writing over the moved facts.
                DB::table('content.home_sections')->where('id', $row->id)->update(['settings' => $json, 'version' => DB::raw('version + 1')]);

                continue;
            }

            $now = Carbon::now();
            DB::table('content.home_sections')->insert([
                'id' => strtolower((string) Str::ulid()),
                'store_id' => $hero->store_id,
                'type' => 'PROMISES',
                'position' => (int) DB::table('content.home_sections')->where('store_id', $hero->store_id)->max('position') + 1,
                'is_visible' => false,
                'settings' => $json,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // The cached home and frame copies hold the old rows (up to an hour): forgotten once the move is done.
        app(ContentCache::class)->invalidate();
    }

    public function down(): void
    {
        // The facts are promises now; nothing tells them apart to take back.
    }

    /**
     * A fact as a promise; one with neither a figure nor a caption is none.
     *
     * @return array{icon: string, title: array{ar: string, en: string}, line: array{ar: string|null, en: string|null}}|null
     */
    private static function promise(mixed $fact): ?array
    {
        $fact = is_array($fact) ? $fact : [];
        // A fact saved before 2026-11-16 is a caption alone ({ar, en}).
        $caption = self::pair(array_key_exists('caption', $fact) ? $fact['caption'] : $fact);
        $figure = self::pair($fact['figure'] ?? null);

        if ($figure['ar'] === '' && $figure['en'] === '' && $caption['ar'] === '' && $caption['en'] === '') {
            return null;
        }

        $hasFigure = $figure['ar'] !== '' || $figure['en'] !== '';

        // An empty line saved as the app saves one: null in each language.
        return ['icon' => 'award', 'title' => $hasFigure ? $figure : $caption, 'line' => $hasFigure ? $caption : ['ar' => null, 'en' => null]];
    }

    /**
     * @return array{ar: string, en: string}
     */
    private static function pair(mixed $value): array
    {
        $value = is_array($value) ? $value : [];

        return ['ar' => is_string($value['ar'] ?? null) ? $value['ar'] : '', 'en' => is_string($value['en'] ?? null) ? $value['en'] : ''];
    }
};
