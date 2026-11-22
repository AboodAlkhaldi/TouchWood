<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPair;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPairHandler;
use Modules\Catalog\Application\Command\DeleteWordPair\DeleteWordPair;
use Modules\Catalog\Application\Command\DeleteWordPair\DeleteWordPairHandler;
use Modules\Catalog\Presentation\Http\Request\CatalogFormRequest;
use Modules\Catalog\Presentation\Http\Resource\ListPages;

/**
 * The search words screen (catalog.md §1.11, §4.4 S7): the shared word pairs — added and deleted,
 * never edited (amendment 2(d)) — and the searches that found nothing, under
 * `catalog.search_word.manage` with All stores (§3).
 */
final readonly class SearchWordsController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_search_words', 'admin'];

    // What asks who is acting - the pages, the store choices - is taken by each action, never kept here:
    // a controller is kept on its route, longer than one request.
    public function __construct(
        private Page $page,
    ) {}

    public function index(Request $request, ListPages $pages): Response
    {
        $store = $request->query('store');
        $page = $request->integer('page', 1);

        return $this->page->render(
            'Catalog/Admin/SearchWords/Index',
            $pages->searchWords(is_string($store) && trim($store) !== '' ? trim($store) : null, max($page, 1))->toArray(),
            self::WORDS,
        );
    }

    public function add(CatalogFormRequest $request, AddWordPairHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new AddWordPair(
            $request->text('word_a'),
            $request->text('word_b'),
        )), 'catalog::admin_search_words.toast.added', ['word_a', 'word_b', 'word_pair']);
    }

    public function delete(Request $request, string $pair, DeleteWordPairHandler $handler): RedirectResponse
    {
        return CatalogRefusals::act($request, fn () => $handler->handle(new DeleteWordPair($pair)), 'catalog::admin_search_words.toast.deleted');
    }
}
