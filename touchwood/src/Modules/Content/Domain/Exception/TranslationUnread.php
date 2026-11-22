<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * Publish, Schedule, Show, switching the bar on, or a change to something shoppers see, while Translate's English on
 * it is still unread (content.md §1.3, P14): the person ticks "I read the translated English." first, in this session
 * or a later one.
 */
final class TranslationUnread extends ContentError
{
    public function __construct()
    {
        parent::__construct('The translated English is not read yet.');
    }

    public function type(): string
    {
        return 'content.translation_unread';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
