<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Modules\Content\Domain\Model\DocumentKind;

/** Which of content's tables hold each kind of document (content.md §5), and the column naming their item. */
final readonly class DocumentTables
{
    private function __construct(
        public string $items,
        public string $texts,
        public string $images,
        public ?string $products,
        public ?string $photos,
        public string $owner,
    ) {}

    public static function of(DocumentKind $kind): self
    {
        return match ($kind) {
            DocumentKind::Page => new self('content.pages', 'content.page_translations', 'content.page_images', null, null, 'page_id'),
            DocumentKind::Post => new self('content.blog_posts', 'content.blog_post_translations', 'content.post_images', 'content.blog_post_products', null, 'post_id'),
            DocumentKind::Project => new self('content.projects', 'content.project_translations', 'content.project_images', 'content.project_products', 'content.project_photos', 'project_id'),
        };
    }
}
