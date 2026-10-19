<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query;

/** A page of a store's "Favourited products": `next` is what to ask for the following page, null on the last. */
final readonly class FavouritesPage
{
    /**
     * @param  list<FavouriteRow>  $rows
     */
    public function __construct(
        public array $rows,
        public ?string $next,
    ) {}
}
