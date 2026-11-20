<?php

declare(strict_types=1);

namespace Modules\Content\Application\Query\Policies;

use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Policies\PolicyRules;
use Modules\Content\Application\Policies\PolicyWriter;
use Modules\Content\Application\Query\ScreenReads;
use Modules\Content\Application\Text\MarkText;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Model\Policy;
use Modules\Content\Domain\Model\PolicyText;
use Modules\Content\Domain\Model\Publication;
use Modules\Content\Domain\Repository\Policies;
use Modules\Content\Domain\Repository\UnreadTranslations;
use Modules\Platform\Public\Contracts\StaffNames;
use Shared\Application\Authorizer;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Content › Policies shows (flags-2026-11.md S3.7), under `content.policy.manage`: a tab of the list - the
 * policies serving any store the person holds the job in (a Super Admin, every one), last changed first, 50 at a time,
 * as Title · Kind · Stores · Version · Changed · By - and one policy's form, its bodies **written back as the editor's
 * marks** (content.md §1.2). A policy serving none of the person's stores is not found, as an unknown id is
 * (`PolicyRules::served`).
 */
final readonly class PoliciesHandler
{
    public const string PERMISSION = ContentPermissions::POLICY_MANAGE;

    public function __construct(
        private ContentAccess $access,
        private Policies $policies,
        private PolicyRules $rules,
        private ScreenReads $reads,
        private UnreadTranslations $marks,
        private StaffNames $names,
        private Authorizer $authorizer,
    ) {}

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     *
     * @throws Unauthorized
     */
    public function list(Publication $tab, ?string $after = null): array
    {
        // A list across stores (the reads' idiom): the stores the person holds the job in, none at all refused.
        $held = $this->authorizer->storesWith(self::PERMISSION);

        if ($held === []) {
            throw new Unauthorized(self::PERMISSION);
        }

        $read = $this->reads->policies($held === null ? null : array_map(static fn (StoreId $store): string => $store->value, $held), $tab, $after);
        // "By" is a name (S3.7): one read for the page's rows; someone no longer staff reads as nobody.
        $names = $this->names->forReader(array_values(array_filter(array_map(static fn (array $row): ?string => $row['changedBy'], $read['rows']))));

        return [
            'rows' => array_map(static fn (array $row): array => [...$row, 'changedBy' => $row['changedBy'] === null ? null : ($names[strtolower($row['changedBy'])] ?? null)?->name], $read['rows']),
            'counts' => $read['counts'],
        ];
    }

    /**
     * Before a new policy's form: whoever holds the job in no store at all is refused.
     *
     * @throws Unauthorized
     */
    public function mayCreate(): void
    {
        $this->access->holds(self::PERMISSION);
    }

    /**
     * One policy as its form shows it.
     *
     * @return array<string, mixed>
     *
     * @throws ContentError|Unauthorized
     */
    public function policy(string $id): array
    {
        $this->access->holds(self::PERMISSION);
        $policy = $this->policies->find($id);
        $stores = $this->rules->served($policy);
        // The job checked in a store it serves that is the person's: one they may work in first, so a policy also serving
        // an off store opens; serving only off ones, refused as off to whoever may not switch stores (content.md §1.1 rule 7).
        $held = $this->access->storesHeld(self::PERMISSION);
        $theirs = $held === null ? $stores : array_values(array_intersect($stores, $held));
        $this->access->authorize(self::PERMISSION, $this->rules->pickable($theirs)[0] ?? $theirs[0] ?? null, item: true);

        /** @var Policy $policy found, or `served` refused it */
        return [
            'id' => $policy->id,
            'kind' => $policy->kind->value,
            'stores' => $policy->stores,
            'status' => $policy->status->value,
            'publishedAt' => $policy->publishedAt?->format(DATE_ATOM),
            'textVersion' => $policy->textVersion,
            'version' => $policy->version,
            'translationUnread' => $this->marks->has(PolicyWriter::KIND, $policy->id),
            'share_media_ar' => $policy->shareAr,
            'share_media_en' => $policy->shareEn,
            'texts' => array_map(static fn (PolicyText $text): array => [
                'title' => $text->title,
                'slug' => $text->slug,
                'body' => MarkText::marks($text->body),
                'images' => array_map(static fn (array $image): string => $image['media'], $text->images),
                'seoTitle' => $text->seoTitle,
                'seoDescription' => $text->seoDescription,
            ], $policy->texts),
        ];
    }
}
