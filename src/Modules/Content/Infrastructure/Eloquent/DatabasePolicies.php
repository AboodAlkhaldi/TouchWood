<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Domain\Model\Policy;
use Modules\Content\Domain\Model\PolicyKind;
use Modules\Content\Domain\Model\PolicyText;
use Modules\Content\Domain\Model\PolicyVersion;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\Repository\Policies;
use Modules\Content\Domain\ValueObject\RichText;
use stdClass;

/**
 * `Policies` through the query builder (flags-2026-11.md S3; CONVENTIONS "Database: Laravel's tools first"): a policy
 * with its translations, body images and stores, read and written whole; its versions written once and read by number.
 * A row no body names is removed on save, since every save rewrites them.
 */
final readonly class DatabasePolicies implements Policies
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function find(string $id): ?Policy
    {
        return $this->read($id, false);
    }

    public function lock(string $id): ?Policy
    {
        return $this->read($id, true);
    }

    public function add(Policy $policy, ?string $by): void
    {
        ForeignKeyRefusals::guard(function () use ($policy, $by): void {
            $this->db->table('content.policies')->insert([
                'id' => $policy->id,
                'kind' => $policy->kind->value,
                'created_by' => $by,
                'created_at' => Rows::at($policy->updatedAt),
                ...self::written($policy),
            ]);
            $this->writeParts($policy);
        });
    }

    public function update(Policy $policy): void
    {
        ForeignKeyRefusals::guard(function () use ($policy): void {
            $this->db->table('content.policies')->where('id', $policy->id)->update(self::written($policy));
            $this->writeParts($policy);
        });
    }

    public function updateRow(Policy $policy): void
    {
        ForeignKeyRefusals::guard(fn () => $this->db->table('content.policies')->where('id', $policy->id)->update(self::written($policy)));
    }

    public function delete(string $id): void
    {
        $this->db->table('content.policies')->where('id', strtolower($id))->delete();
    }

    public function publishedOfKind(PolicyKind $kind, array $storeIds, string $exceptId): array
    {
        if ($storeIds === []) {
            return [];
        }

        $ids = $this->db->table('content.policies')
            ->where('kind', $kind->value)
            ->where('status', Publication::Published->value)
            ->where('id', '<>', strtolower($exceptId))
            ->whereIn('id', $this->db->table('content.policy_stores')->select('policy_id')->whereIn('store_id', array_map(strtolower(...), $storeIds)))
            ->orderBy('id')
            ->pluck('id')
            ->all();

        return array_values(array_filter(array_map(fn (mixed $id): ?Policy => $this->read((string) $id, false), $ids)));
    }

    public function latestVersion(string $policyId): ?PolicyVersion
    {
        $number = $this->db->table('content.policy_versions')->where('policy_id', strtolower($policyId))->max('number');

        return $number === null ? null : $this->version($policyId, (int) $number);
    }

    public function version(string $policyId, int $number): ?PolicyVersion
    {
        $id = strtolower($policyId);
        $row = $this->db->table('content.policy_versions')->where('policy_id', $id)->where('number', $number)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $images = [];

        foreach ($this->db->table('content.policy_version_images')->where('policy_id', $id)->where('number', $number)->orderBy('locale')->orderBy('ref')->get() as $image) {
            /** @var stdClass $image */
            $images[(string) $image->locale][(int) $image->ref] = ['media' => (string) $image->media_id, 'alt' => (string) $image->alt];
        }

        $texts = [];

        foreach (['ar', 'en'] as $locale) {
            $texts[$locale] = [
                'title' => (string) $row->{"title_{$locale}"},
                'body' => RichText::fromJson("body_{$locale}", (string) $row->{"body_{$locale}"}),
                'images' => $images[$locale] ?? [],
            ];
        }

        return new PolicyVersion($id, $number, $texts, Rows::moment($row->published_at) ?? throw new \LogicException('A version with no moment.'), Rows::text($row->published_by));
    }

    public function addVersion(PolicyVersion $version): void
    {
        ForeignKeyRefusals::guard(function () use ($version): void {
            $row = ['policy_id' => $version->policyId, 'number' => $version->number, 'published_at' => Rows::at($version->publishedAt), 'published_by' => $version->publishedBy];

            foreach (['ar', 'en'] as $locale) {
                $text = $version->texts[$locale] ?? throw new \LogicException("A version with no {$locale} text.");
                $row["title_{$locale}"] = $text['title'];
                $row["body_{$locale}"] = $text['body']->toJson();
            }

            $this->db->table('content.policy_versions')->insert($row);

            foreach ($version->texts as $locale => $text) {
                foreach ($text['images'] as $ref => $image) {
                    $this->db->table('content.policy_version_images')->insert(['policy_id' => $version->policyId, 'number' => $version->number, 'locale' => $locale, 'ref' => $ref, 'media_id' => $image['media'], 'alt' => $image['alt']]);
                }
            }
        });
    }

    public function settlePeriods(Policy $policy, DateTimeImmutable $at): void
    {
        $id = strtolower($policy->id);
        $wanted = [];

        if ($policy->isPublished()) {
            $slugs = ['slug_ar' => $policy->text('ar')?->slug, 'slug_en' => $policy->text('en')?->slug];

            if ($slugs['slug_ar'] === null || $slugs['slug_en'] === null) {
                throw new \LogicException('A published policy has both languages.');
            }

            foreach ($policy->stores as $store) {
                $wanted[strtolower($store)] = ['number' => $policy->textVersion, ...$slugs];
            }
        }

        foreach ($this->db->table('content.policy_periods')->where('policy_id', $id)->whereNull('ended_at')->get() as $row) {
            /** @var stdClass $row */
            $store = (string) $row->store_id;
            $want = $wanted[$store] ?? null;

            if ($want !== null && (int) $row->number === $want['number'] && (string) $row->slug_ar === $want['slug_ar'] && (string) $row->slug_en === $want['slug_en']) {
                // Still in force as it is: nothing to open there.
                unset($wanted[$store]);

                continue;
            }

            $this->db->table('content.policy_periods')->where('id', $row->id)->update(['ended_at' => Rows::at($at)]);
        }

        foreach ($wanted as $store => $want) {
            $this->db->table('content.policy_periods')->insert([
                'id' => strtolower((string) Str::ulid()),
                'policy_id' => $id,
                'store_id' => $store,
                'started_at' => Rows::at($at),
                ...$want,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function written(Policy $policy): array
    {
        return [
            'status' => $policy->status->value,
            'published_at' => Rows::at($policy->publishedAt),
            'text_version' => $policy->textVersion,
            'share_media_ar' => $policy->shareAr,
            'share_media_en' => $policy->shareEn,
            'version' => $policy->version,
            'updated_by' => $policy->updatedBy,
            'updated_at' => Rows::at($policy->updatedAt),
        ];
    }

    private function writeParts(Policy $policy): void
    {
        $owner = ['policy_id' => $policy->id];

        // Every part out, then written again as the policy is now.
        $this->db->table('content.policy_images')->where($owner)->delete();
        $this->db->table('content.policy_translations')->where($owner)->delete();
        $this->db->table('content.policy_stores')->where($owner)->delete();

        foreach ($policy->texts as $locale => $text) {
            $this->db->table('content.policy_translations')->insert([
                ...$owner,
                'locale' => $locale,
                'title' => $text->title,
                'slug' => $text->slug,
                'body' => $text->body->toJson(),
                'seo_title' => $text->seoTitle,
                'seo_description' => $text->seoDescription,
            ]);

            foreach ($text->images as $ref => $image) {
                $this->db->table('content.policy_images')->insert([...$owner, 'locale' => $locale, 'ref' => $ref, 'media_id' => $image['media'], 'alt' => $image['alt']]);
            }
        }

        foreach ($policy->stores as $store) {
            $this->db->table('content.policy_stores')->insert([...$owner, 'store_id' => $store]);
        }
    }

    private function read(string $id, bool $lock): ?Policy
    {
        $id = strtolower($id);
        $query = $this->db->table('content.policies')->where('id', $id);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $row instanceof stdClass) {
            return null;
        }

        $images = [];

        foreach ($this->db->table('content.policy_images')->where('policy_id', $id)->orderBy('locale')->orderBy('ref')->get() as $image) {
            /** @var stdClass $image */
            $images[(string) $image->locale][(int) $image->ref] = ['media' => (string) $image->media_id, 'alt' => (string) $image->alt];
        }

        $texts = [];

        foreach ($this->db->table('content.policy_translations')->where('policy_id', $id)->orderBy('locale')->get() as $text) {
            /** @var stdClass $text */
            $locale = (string) $text->locale;
            $texts[$locale] = new PolicyText(
                (string) $text->title,
                (string) $text->slug,
                RichText::fromJson("body_{$locale}", (string) $text->body),
                Rows::text($text->seo_title),
                Rows::text($text->seo_description),
                $images[$locale] ?? [],
            );
        }

        $stores = array_values(array_map(static fn (mixed $store): string => (string) $store, $this->db->table('content.policy_stores')->where('policy_id', $id)->orderBy('store_id')->pluck('store_id')->all()));

        return new Policy(
            $id,
            PolicyKind::from((string) $row->kind),
            $stores,
            Publication::from((string) $row->status),
            Rows::moment($row->published_at),
            $texts,
            Rows::text($row->share_media_ar),
            Rows::text($row->share_media_en),
            (int) $row->text_version,
            (int) $row->version,
            Rows::text($row->updated_by),
            Rows::moment($row->updated_at),
        );
    }
}
