<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Publish or Schedule with an image whose sizes are still being made, or failed (content.md §1.1 rule 10,
 * §7), naming the image.
 */
final class ImageNotReady extends ContentError
{
    /**
     * @param  string  $image  the image, as the form names it
     * @param  bool  $failed  whether its sizes failed (else they are still being made)
     */
    public function __construct(
        public readonly string $image,
        public readonly bool $failed,
    ) {
        parent::__construct("The image {$image} is not ready.");
    }

    public function type(): string
    {
        return 'content.image_not_ready';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        // The image is named by its box, as a field is (`content::errors.fields`), so the words can say which.
        return ['image' => $this->image, 'attribute' => $this->image, 'rule' => $this->failed ? 'failed' : 'pending'];
    }
}
