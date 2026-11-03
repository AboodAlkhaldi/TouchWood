<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Bell;

use Illuminate\Contracts\Translation\Translator;
use Modules\Ops\Application\Query\CountUnread\CountUnread;
use Modules\Ops\Application\Query\CountUnread\CountUnreadHandler;
use Modules\Platform\Public\Contracts\ShopFramePart;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

/**
 * Ops' part of every shop page's frame (ops.md §4.15 O2): for a signed-in customer the bell's number in
 * this store, one query, and its words. For anyone else nothing to draw and nothing read.
 */
final readonly class ShopBellPart implements ShopFramePart
{
    public function __construct(
        private Translator $translator,
        private CountUnreadHandler $count,
        private ActorContext $actors,
    ) {}

    public function partFor(StoreDto $store, string $locale): array
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            return ['unread' => null, 'words' => null];
        }

        return [
            'unread' => $this->count->handle(new CountUnread),
            'words' => (array) $this->translator->get('ops::bell', [], $locale),
        ];
    }
}
