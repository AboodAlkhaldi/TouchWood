<?php

declare(strict_types=1);

namespace Tests\Modules\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\UploadImport\UploadImport;
use Modules\Catalog\Application\Command\UploadImport\UploadImportHandler;
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
