<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A value the domain refuses (catalog.md §7): a name too long or on two lines, a slug with a
 * character it does not take, a label of three words, a swatch that is not a colour. A refusal with its own words
 * names its `rule` (`catalog::errors.invalid_catalog_attribute.rules.{rule}`): a link that is not YouTube's.
 */
final class InvalidCatalogAttribute extends CatalogError
{
    /** A pasted link that is not a YouTube video's (flags-2026-11.md S4.4): "Paste a YouTube link." */
    public const string YOUTUBE_LINK = 'youtube_link';

    /** A video a product already has, pasted again. */
    public const string VIDEO_ONCE = 'video_once';

    public function __construct(public readonly string $attribute, public readonly string $reason, public readonly ?string $rule = null)
    {
        parent::__construct("Invalid {$attribute}: {$reason}");
    }

    public function type(): string
    {
        return 'catalog.invalid_catalog_attribute';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Invalid;
    }

    public function context(): array
    {
        return $this->rule === null ? ['attribute' => $this->attribute] : ['attribute' => $this->attribute, 'rule' => $this->rule];
    }
}
