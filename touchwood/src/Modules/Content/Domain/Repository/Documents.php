<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\Document;
use Modules\Content\Domain\Model\DocumentKind;

/**
 * Pages, blog posts and projects (content.md §1.11-§1.13, §5): each with its translations, its body images and what
 * its kind adds (a post's products; a project's photos and products), written and read whole.
 */
interface Documents
{
    public function find(DocumentKind $kind, string $id): ?Document;

    /** The item's row locked for the change (`FOR UPDATE`), then read whole. */
    public function lock(DocumentKind $kind, string $id): ?Document;

    public function add(Document $document, ?string $by): void;

    /** Writes the item whole: its row, its translations, images, products and photos replaced. */
    public function update(Document $document): void;

    /** Writes the item's own row only - its state, its version - leaving its words, images and photos as they are. */
    public function updateRow(Document $document): void;

    public function delete(DocumentKind $kind, string $id): void;

    /** How many posts a blog category holds. */
    public function postsInCategory(string $categoryId): int;
}
