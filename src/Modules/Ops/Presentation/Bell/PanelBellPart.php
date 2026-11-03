<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Bell;

use Illuminate\Contracts\Translation\Translator;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Ops\Application\Query\CountUnread\CountUnread;
use Modules\Ops\Application\Query\CountUnread\CountUnreadHandler;
use Modules\Platform\Public\Contracts\AdminFramePart;
use Shared\Application\ActorContext;

/**
 * Ops' part of every panel page's frame (ops.md §4.15 O1): the bell's number, whether its rows name their
 * store (two or more stores, Q9; every store the bell spans, off ones too, so not the menu's list), and
 * its words, so a page naming none of Ops' words still draws it. One query, the count: the rest the
 * request has read already.
 */
final readonly class PanelBellPart implements AdminFramePart
{
    public function __construct(
        private Translator $translator,
        private CountUnreadHandler $count,
        private AccessApi $access,
        private ActorContext $actors,
    ) {}

    public function partFor(string $locale): array
    {
        return [
            'unread' => $this->count->handle(new CountUnread),
            'manyStores' => count($this->access->staffStores((string) $this->actors->current()->id)) > 1,
            'words' => (array) $this->translator->get('ops::bell', [], $locale),
        ];
    }
}
