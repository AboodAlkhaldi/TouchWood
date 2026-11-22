<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * The three kinds of Content item that publish at once with a body (content.md §1.11-§1.13, §4.1): a page, a
 * blog post, a project. They share their rules - title, address, body and SEO per language, Draft ⇄ Published,
 * the address history, the version check - and each adds its own (a post's summary, cover, category and
 * products; a project's city, photos and products).
 */
enum DocumentKind: string
{
    case Page = 'PAGE';
    case Post = 'POST';
    case Project = 'PROJECT';

    /** The shop's path under the store and language that names one by its address (§4.5). */
    public function pathPrefix(): string
    {
        return match ($this) {
            self::Page => '/pages/',
            self::Post => '/blog/',
            self::Project => '/projects/',
        };
    }

    /** The audit's and the words' name for it. */
    public function key(): string
    {
        return strtolower($this->value);
    }

    /** At most how many products it may link (§1.12, §1.13); a page links none. */
    public function maxProducts(): int
    {
        return match ($this) {
            self::Page => 0,
            self::Post => 8,
            self::Project => 20,
        };
    }
}
