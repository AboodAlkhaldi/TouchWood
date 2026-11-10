<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Media;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\ValueObject\RichText;
use Modules\Content\Infrastructure\Eloquent\Rows;
use Modules\Platform\Public\Contracts\MediaUsage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Platform\Public\Dto\MediaUseDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * Content's files as Platform media (content.md §1.1 rule 10; `[ACCEPTED 2026-11-10]`): **a file in use by something
 * scheduled, live, shown or published blocks its delete; one used by a draft, a hidden section or something ended is
 * detached** - and a body image's block goes with it, so no text is left naming an image that is gone.
 *
 * | Where | Blocks while |
 * |---|---|
 * | a home section's logo | the section is shown |
 * | a banner's artwork, a pop-up's image | it is scheduled and has not ended |
 * | a page's, post's or project's share image, cover, photo or body image | it is published |
 *
 * Detaching changes the item, so it takes the item's own permission in the item's store, as editing it would, writes
 * a new version (so an open form is told), is audited, and replaces the shop's cached copy.
 */
final readonly class ContentMediaUsage implements MediaUsage
{
    /** @var array<string, array{0: string, 1: string, 2: string}> kind => [items table, permission, subject] */
    private const array DOCUMENTS = [
        'page' => ['content.pages', ContentPermissions::PAGE_MANAGE, 'content.page'],
        'post' => ['content.blog_posts', ContentPermissions::BLOG_MANAGE, 'content.post'],
        'project' => ['content.projects', ContentPermissions::PROJECT_MANAGE, 'content.project'],
    ];

    public function __construct(
        private ConnectionInterface $db,
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private ActorContext $actors,
        private ContentCache $cache,
    ) {}

    public function usesOf(string $mediaId): array
    {
        return array_map(
            static fn (array $use): MediaUseDto => new MediaUseDto($use['subject'], $use['id'], $use['blocks']),
            $this->uses(strtolower($mediaId)),
        );
    }

    public function detach(string $mediaId): void
    {
        $mediaId = strtolower($mediaId);
        $uses = $this->uses($mediaId);

        if ($uses === []) {
            return;
        }

        foreach ($uses as $use) {
            $this->authorizer->authorize($use['permission'], PermissionScope::store(StoreId::fromString($use['store'])));
        }

        $actor = $this->actors->current();
        $by = $actor->type === ActorType::Staff ? $actor->id : null;

        foreach ($uses as $use) {
            match ($use['subject']) {
                'content.home_section' => $this->db->table('content.home_section_media')->where('section_id', $use['id'])->where('media_id', $mediaId)->delete(),
                'content.banner' => $this->db->table('content.banner_images')->where('banner_id', $use['id'])->where('media_id', $mediaId)->delete(),
                'content.popup' => $this->db->table('content.popups')->where('id', $use['id'])->update(['media_id' => null]),
                default => $this->detachFromDocument($use['subject'], $use['id'], $mediaId),
            };

            $table = match ($use['subject']) {
                'content.home_section' => 'content.home_sections',
                'content.banner' => 'content.banners',
                'content.popup' => 'content.popups',
                default => self::DOCUMENTS[substr($use['subject'], strlen('content.'))][0],
            };

            // A new version: a form opened before the detach is told rather than writing the file back.
            // Who changed it is the person deleting the file; the system's delete (no staff member) leaves the last name.
            $this->db->table($table)->where('id', $use['id'])->increment('version', 1, ['updated_at' => Rows::at(Moments::now()), ...($by === null ? [] : ['updated_by' => $by])]);
            $this->platform->recordAudit(ContentAudit::entry("{$use['subject']}.image_detached", $use['subject'], $use['id'], $use['store'], AuditChanges::none()->changed('media_id', $mediaId, null)));
        }

        $this->cache->invalidate();
    }

    /**
     * Every use of the file, each with its store, its permission and whether it blocks the delete.
     *
     * @return list<array{subject: string, id: string, store: string, permission: string, blocks: bool}>
     */
    private function uses(string $mediaId): array
    {
        $now = Rows::at(Moments::now());
        $live = '(is_scheduled AND (ends_at IS NULL OR ends_at > ?::timestamptz))';
        $sql = [
            "SELECT DISTINCT 'content.home_section' AS subject, s.id, s.store_id, s.is_visible AS blocks FROM content.home_section_media m JOIN content.home_sections s ON s.id = m.section_id WHERE m.media_id = ?",
            "SELECT DISTINCT 'content.banner', b.id, b.store_id, {$live} FROM content.banner_images i JOIN content.banners b ON b.id = i.banner_id WHERE i.media_id = ?",
            "SELECT 'content.popup', id, store_id, {$live} FROM content.popups WHERE media_id = ?",
            "SELECT 'content.page', id, store_id, status = 'PUBLISHED' FROM content.pages WHERE ? IN (share_media_ar, share_media_en) OR id IN (SELECT page_id FROM content.page_images WHERE media_id = ?)",
            "SELECT 'content.post', id, store_id, status = 'PUBLISHED' FROM content.blog_posts WHERE ? IN (share_media_ar, share_media_en, cover_media_id) OR id IN (SELECT post_id FROM content.post_images WHERE media_id = ?)",
            "SELECT 'content.project', id, store_id, status = 'PUBLISHED' FROM content.projects WHERE ? IN (share_media_ar, share_media_en) OR id IN (SELECT project_id FROM content.project_images WHERE media_id = ?) OR id IN (SELECT project_id FROM content.project_photos WHERE media_id = ?)",
        ];
        $bindings = [$mediaId, $now, $mediaId, $now, $mediaId, $mediaId, $mediaId, $mediaId, $mediaId, $mediaId, $mediaId, $mediaId];

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(implode(' UNION ALL ', $sql).' ORDER BY 1, 2', $bindings);

        return array_map(static fn (stdClass $row): array => [
            'subject' => (string) $row->subject,
            'id' => (string) $row->id,
            'store' => (string) $row->store_id,
            'permission' => match ((string) $row->subject) {
                'content.home_section' => ContentPermissions::HOMEPAGE_MANAGE,
                'content.banner' => ContentPermissions::BANNER_MANAGE,
                'content.popup' => ContentPermissions::POPUP_MANAGE,
                default => self::DOCUMENTS[substr((string) $row->subject, strlen('content.'))][1],
            },
            'blocks' => (bool) $row->blocks,
        ], $rows);
    }

    /** Takes the file out of a draft page, post or project: its share images, cover, photos, and body images with their blocks. */
    private function detachFromDocument(string $subject, string $id, string $mediaId): void
    {
        $kind = substr($subject, strlen('content.'));
        [$items] = self::DOCUMENTS[$kind];
        [$texts, $images, $owner] = match ($kind) {
            'page' => ['content.page_translations', 'content.page_images', 'page_id'],
            'post' => ['content.blog_post_translations', 'content.post_images', 'post_id'],
            default => ['content.project_translations', 'content.project_images', 'project_id'],
        };

        foreach (['share_media_ar', 'share_media_en', ...($kind === 'post' ? ['cover_media_id'] : [])] as $column) {
            $this->db->table($items)->where('id', $id)->where($column, $mediaId)->update([$column => null]);
        }

        if ($kind === 'project') {
            $photos = array_map(static fn (stdClass $row): string => (string) $row->media_id, $this->db->select('SELECT media_id FROM content.project_photos WHERE project_id = ? ORDER BY position', [$id]));
            $this->db->table('content.project_photos')->where('project_id', $id)->delete();

            foreach (array_values(array_filter($photos, static fn (string $photo): bool => $photo !== $mediaId)) as $position => $photo) {
                $this->db->table('content.project_photos')->insert(['project_id' => $id, 'position' => $position, 'media_id' => $photo]);
            }
        }

        /** @var list<stdClass> $refs */
        $refs = $this->db->select("SELECT locale, ref FROM {$images} WHERE {$owner} = ? AND media_id = ?", [$id, $mediaId]);

        foreach ($refs as $ref) {
            $locale = (string) $ref->locale;
            $row = $this->db->selectOne("SELECT body FROM {$texts} WHERE {$owner} = ? AND locale = ?", [$id, $locale]);

            if ($row instanceof stdClass) {
                // The image's block goes with it (§1.1 rule 10), and the text is checked again as it is kept.
                $document = RichText::fromJson("body_{$locale}", (string) $row->body)->toArray();
                $document['blocks'] = array_values(array_filter($document['blocks'], static fn (array $block): bool => ! (($block['type'] ?? null) === 'image' && ($block['ref'] ?? null) === (int) $ref->ref)));
                $this->db->table($texts)->where($owner, $id)->where('locale', $locale)->update(['body' => RichText::of("body_{$locale}", $document)->toJson()]);
            }

            $this->db->table($images)->where($owner, $id)->where('locale', $locale)->where('ref', (int) $ref->ref)->delete();
        }
    }
}
