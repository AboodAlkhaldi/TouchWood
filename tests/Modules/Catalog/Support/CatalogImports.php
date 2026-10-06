<?php

declare(strict_types=1);

namespace Tests\Modules\Catalog\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\BringInImport\BringInImport;
use Modules\Catalog\Application\Command\BringInImport\BringInImportHandler;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProducts;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProductsHandler;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNames;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNamesHandler;
use Modules\Catalog\Application\Command\UploadImport\UploadImport;
use Modules\Catalog\Application\Command\UploadImport\UploadImportHandler;
use Tests\Modules\Access\Support\AccessFixtures;
use ZipArchive;

/**
 * Products files for the import's tests (catalog.md §1.12): written to temporary files, uploaded
 * through the real handler, and removed after each test (cleanUp() in afterEach).
 */
final class CatalogImports
{
    /** @var list<string> */
    private static array $files = [];

    /**
     * A product with no photos, for a JSON uploaded alone.
     *
     * @param  array<string, mixed>  $with
     * @return array<string, mixed>
     */
    public static function product(string $code, array $with = []): array
    {
        return [
            'name' => ['ar' => 'منتج '.$code, 'en' => 'Product '.$code],
            'variants' => [['code' => $code]],
            ...$with,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $products
     */
    public static function json(array $products): string
    {
        return json_encode(['format' => 'touchwood-products/1', 'products' => $products], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function temp(string $contents): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tw-');
        file_put_contents($path, $contents);
        self::remember($path);

        return $path;
    }

    /**
     * @param  array<string, string>  $files  path inside the zip => contents
     */
    public static function zip(array $files): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tw-');
        self::remember($path);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    public static function upload(string $path, string $fileName = 'products.json'): string
    {
        return app(UploadImportHandler::class)->handle(new UploadImport($path, $fileName));
    }

    /**
     * These products uploaded as a JSON alone, as the Super Admin acting now.
     *
     * @param  list<array<string, mixed>>  $products
     */
    public static function uploadProducts(array $products): string
    {
        return self::upload(self::temp(self::json($products)));
    }

    /**
     * @return array<string, list<string>> kind => the names as written, sorted
     */
    public static function names(string $importId): array
    {
        $names = [];

        foreach (DB::table('catalog.import_names')->where('import_id', $importId)->orderBy('kind')->get() as $row) {
            $names[(string) $row->kind][] = (string) $row->written;
        }

        foreach ($names as &$written) {
            sort($written);
        }

        return $names;
    }

    /** The id of the import's name written so, of this kind. */
    public static function nameId(string $importId, string $kind, string $written): string
    {
        $id = DB::table('catalog.import_names')->where('import_id', $importId)->where('kind', $kind)->where('written', $written)->value('id');

        return is_string($id) ? $id : throw new \LogicException("No {$kind} {$written} in the import.");
    }

    /** The id of the import's product at this place in the file. */
    public static function productId(string $importId, int $number): string
    {
        $id = DB::table('catalog.import_products')->where('import_id', $importId)->where('number', $number)->value('id');

        return is_string($id) ? $id : throw new \LogicException("No product {$number} in the import.");
    }

    /**
     * A real image's bytes — JPEG or PNG by the name's extension — each size its own, so the media
     * library never takes two for the same image.
     */
    public static function image(string $name, int $size): string
    {
        // The fake's file lasts only while the object does: read it before letting the object go.
        $image = UploadedFile::fake()->image($name, $size, $size);

        return (string) file_get_contents($image->getPathname());
    }

    /**
     * Every name of the import decided: those given by what they are written as, the rest refused.
     *
     * @param  array<string, array<string, string>>  $decisions  written => decision
     */
    public static function decideNames(string $importId, array $decisions = []): void
    {
        $all = [];

        foreach (DB::table('catalog.import_names')->where('import_id', $importId)->get(['id', 'written']) as $row) {
            $all[] = ['name_id' => (string) $row->id, ...($decisions[(string) $row->written] ?? ['decision' => 'REFUSE'])];
        }

        if ($all !== []) {
            app(DecideImportNamesHandler::class)->handle(new DecideImportNames($importId, $all));
        }
    }

    /**
     * @return array<string, string>
     */
    public static function create(string $ar, string $en): array
    {
        return ['decision' => 'CREATE', 'name_ar' => $ar, 'name_en' => $en];
    }

    /** The confirm, then the queued work as the queue runs it. */
    public static function bringIn(string $importId): void
    {
        app(BringInImportHandler::class)->handle(new BringInImport($importId));
        AccessFixtures::asSystem(fn () => app(BringInImportProductsHandler::class)->handle(new BringInImportProducts($importId)));
    }

    /** The product a row of the import became. */
    public static function broughtIn(string $importId, int $number): string
    {
        $id = DB::table('catalog.import_products')->where('import_id', $importId)->where('number', $number)->value('product_id');

        return is_string($id) ? $id : throw new \LogicException("Product {$number} of the import was not brought in.");
    }

    /** A directory, or a file, removed after the test. */
    public static function remember(string $path): void
    {
        self::$files[] = $path;
    }

    public static function cleanUp(): void
    {
        foreach (self::$files as $path) {
            if (is_dir($path)) {
                array_map('unlink', array_filter(glob($path.DIRECTORY_SEPARATOR.'*') ?: [], 'is_file'));
                rmdir($path);
            } else {
                @unlink($path);
            }
        }

        self::$files = [];
    }
}
