<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Frame;

use App\Http\StorefrontArea;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Modules\Ops\Application\Query\CountUnread\CountUnread;
use Modules\Ops\Application\Query\CountUnread\CountUnreadHandler;
use Modules\Platform\Public\Contracts\ShopFramePart;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;

/**
 * Ops' part of every shop page's frame, one per module:
 *
 * - **the bell** (ops.md §4.15 O2): for a signed-in customer, the number in this store, one query, and its words;
 * - **the newsletter box** (§4.15 O11): for a guest alone, its words, read from nothing. Not for a signed-in
 *   customer, whose switch is in the account (§1.3), and not in a staff view, which is a guest to the shop's
 *   session but a staff member looking.
 *
 * Anyone else gets nothing to draw, and nothing is read for them.
 */
final readonly class ShopPart implements ShopFramePart
{
    public function __construct(
        private Translator $translator,
        private CountUnreadHandler $count,
        private ActorContext $actors,
        private Request $request,
    ) {}

    public function partFor(StoreDto $store, string $locale): array
    {
        $actor = $this->actors->current();

        if ($actor->type === ActorType::Customer && $actor->id !== null) {
            return [
                'unread' => $this->count->handle(new CountUnread),
                'words' => (array) $this->translator->get('ops::bell', [], $locale),
                'newsletter' => null,
            ];
        }

        $guest = $actor->type === ActorType::Guest && $this->request->attributes->get(StorefrontArea::STAFF_VIEW) !== true;

        return [
            'unread' => null,
            'words' => null,
            'newsletter' => $guest ? (array) $this->translator->get('ops::newsletter.box', [], $locale) : null,
        ];
    }
}
