<?php

declare(strict_types=1);

namespace Modules\Feedback\Application\Query\ListFavourites;

use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\Query\FavouriteRow;
use Modules\Feedback\Application\Query\FavouritesPage;
use Modules\Feedback\Application\Query\FeedbackQueries;
use Modules\Feedback\Application\Query\FeedbackReader;
use Modules\Feedback\Application\Query\ReadInputs;
use Modules\Feedback\Domain\Exception\InvalidFeedbackAttribute;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Persistence\Ulids;

/**
 * "Customers › Favourited products" (feedback.md §1.4; handoff §14): `feedback.wishlist.view` in the
 * store — each product saved there with how many customers saved it, most saved first (owner,
 * 2026-10-09), 50 a page. An archived product leaves the list (§1.4): Catalog is asked about each
 * batch, and the next batch read past those left out until the page is full or the list ends — **at
 * most five batches a page**, so a page may come back short with a `next` past what was skipped. The
 * place is a count and a product: a count that changes between pages may show a product twice or not
 * at all, as any list ranked by a live count.
 */
final readonly class ListFavouritesHandler
{
    public const string PERMISSION = FeedbackPermissions::WISHLIST_VIEW;

    public const int PAGE = 50;

    /** Batches read for one page at most: the work a page may do past archived products. */
    public const int BATCHES = 5;

    public function __construct(
        private Authorizer $authorizer,
        private ReadInputs $inputs,
        private FeedbackQueries $queries,
        private FeedbackReader $reader,
    ) {}

    /**
     * @throws Unauthorized|InvalidFeedbackAttribute
     */
    public function handle(ListFavourites $query): FavouritesPage
    {
        $store = ReadInputs::store($query->storeId);
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $this->inputs->staffIn(self::PERMISSION, $store);
        $locale = ReadInputs::locale($query->locale);
        $after = self::cursor($query->after);

        $rows = [];
        $more = false;
        $batches = 0;

        do {
            $batch = $this->queries->favouritesIn($store, $after, self::PAGE + 1);
            $ended = count($batch) <= self::PAGE;
            $batch = array_slice($batch, 0, self::PAGE);
            $cards = $this->reader->cards($store, $locale, array_map(static fn (array $row): string => $row[0], $batch));
            $batches++;

            foreach ($batch as [$productId, $savedBy]) {
                $card = FeedbackReader::card($cards, $productId);

                if (! $card->archived && ! $card->removed) {
                    if (count($rows) === self::PAGE) {
                        $more = true;

                        break 2;
                    }

                    $rows[] = new FavouriteRow($card, $savedBy);
                }

                // The last row examined, shown or left out: the next page starts past it.
                $after = [$savedBy, $productId];
            }
        } while (! $ended && $batches < self::BATCHES);

        return new FavouritesPage($rows, ($more || ! $ended) && $after !== null ? $after[0].':'.$after[1] : null);
    }

    /**
     * @return array{int, string}|null
     *
     * @throws InvalidFeedbackAttribute
     */
    private static function cursor(?string $after): ?array
    {
        if ($after === null || trim($after) === '') {
            return null;
        }

        $parts = explode(':', strtolower(trim($after)));

        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! Ulids::valid($parts[1])) {
            throw new InvalidFeedbackAttribute('after', 'not a place in this list');
        }

        return [(int) $parts[0], $parts[1]];
    }
}
