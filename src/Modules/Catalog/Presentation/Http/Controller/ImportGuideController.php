<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Support\Str;
use Inertia\Response;
use Modules\Catalog\Application\Query\ImportGuide\OpenImportGuide;
use Modules\Catalog\Application\Query\ImportGuide\OpenImportGuideHandler;
use Modules\Catalog\Presentation\Http\Resource\ImportGuidePage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The files' guide in the panel (S10, S11 link it): the format's one source, kept in the module
 * itself (`FOLDER`; owner, 2026-11-10, amendment 43) - written by the team, so trusted, its HTML
 * stripped of any raw HTML all the same - and its two example files to download. Its links to the
 * examples point at their downloads here.
 */
final readonly class ImportGuideController
{
    /** @var list<string> */
    private const array WORDS = ['catalog::admin', 'catalog::admin_imports', 'admin'];

    /** The guide and both examples (catalog.md §1.12): the import's tests read the same files. */
    public const string FOLDER = __DIR__.'/../../import-format/';

    public const string GUIDE = 'guide.md';

    /** The example files, by the address that downloads them. */
    public const array EXAMPLES = ['products' => 'products.example.json', 'store-fill' => 'store-fill.example.json'];

    public function __construct(
        private Page $page,
    ) {}

    public function show(OpenImportGuideHandler $handler): Response
    {
        $handler->handle(new OpenImportGuide);
        $html = Str::markdown((string) file_get_contents(self::FOLDER.self::GUIDE), ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        foreach (self::EXAMPLES as $address => $file) {
            $html = str_replace('href="'.$file.'"', 'href="/admin/import-guide/'.$address.'"', $html);
        }

        return $this->page->render('Catalog/Admin/ImportGuide', (new ImportGuidePage($html))->toArray(), self::WORDS);
    }

    public function example(string $file, OpenImportGuideHandler $handler): BinaryFileResponse
    {
        $handler->handle(new OpenImportGuide);
        $name = self::EXAMPLES[$file] ?? abort(404);

        return response()->download(self::FOLDER.$name, $name, ['Content-Type' => 'application/json']);
    }
}
