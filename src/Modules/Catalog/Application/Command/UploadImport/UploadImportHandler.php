<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\UploadImport;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\CatalogCheck;
use Modules\Catalog\Application\Import\CatalogNames;
use Modules\Catalog\Application\Import\FileProblems;
use Modules\Catalog\Application\Import\ImportArchive;
use Modules\Catalog\Application\Import\ImportArchives;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Import\ProductsFile;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Throwable;

/**
 * **Uploading a products file** (catalog.md §1.12, amendment 6): `catalog.import.run`, a Super
 * Admin's. The file is read and checked whole — its format (`ProductsFile`), then against the catalog
 * (`CatalogCheck`) — and refused with every problem listed, or kept as **an import** waiting for its
 * decisions: the names the catalog lacks, each with the products using it; its products as the file
 * gave them, each with the catalog's product already holding its codes; the zip kept until they are
 * brought in. **Nothing in the catalog changes**, so no list's lock is taken. Audited.
 */
final readonly class UploadImportHandler
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    /** Platform's setting for the largest public file (platform.md §1.4), read through its contract. */
    private const string MAX_PUBLIC_BYTES = 'platform.media.max_public_bytes';

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private PlatformApi $platform,
        private ImportArchives $archives,
        private Imports $imports,
        private BrandRepository $brands,
        private CategoryRepository $categories,
        private AttributeRepository $attributes,
        private WarrantyRepository $warranties,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return string the import's id
     *
     * @throws ImportRefused|Unauthorized
     */
    public function handle(UploadImport $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $archive = self::isZip($command->path) ? $this->archives->open($command->path) : null;
        $file = ProductsFile::read($archive->json ?? $this->json($command->path), $archive === null ? null : array_keys($archive->files));

        $problems = new FileProblems;
        $names = CatalogCheck::names($file->products, CatalogNames::load($this->brands, $this->categories, $this->attributes, $this->warranties), $problems);

        if ($archive instanceof ImportArchive) {
            CatalogCheck::photos($file, $archive->files, $this->platform->setting(self::MAX_PUBLIC_BYTES)->int(), $problems);
        }

        $codes = array_merge(...array_map(static fn ($product): array => $product->codes(), $file->products));
        $conflicts = CatalogCheck::conflicts($file, $this->imports->codeHolders($codes), $problems);
        $problems->refuseIfAny();

        $id = $this->imports->nextId();
        $kept = $archive === null ? null : $this->archives->keep($id, $command->path);
        $actor = $this->actors->current();
        $fileName = mb_substr(trim(basename(str_replace('\\', '/', $command->fileName))), 0, 255);

        try {
            $this->db->transaction(function () use ($id, $fileName, $kept, $actor, $names, $file, $conflicts): void {
                $this->imports->addProductsImport($id, $fileName, $kept, $actor->type === ActorType::Staff ? $actor->id : null, $names, $file->products, $conflicts);
                $this->platform->recordAudit(ListAudit::added('import', $id, [
                    'kind' => 'PRODUCTS',
                    'file_name' => $fileName,
                    'products' => count($file->products),
                    'names_to_decide' => count($names),
                    'codes_to_decide' => count($conflicts),
                    'photos' => $kept !== null,
                ]));
            }, 3);
        } catch (Throwable $error) {
            if ($kept !== null) {
                $this->archives->forget($kept);
            }

            throw $error;
        }

        return $id;
    }

    /** A zip begins with a local file header, or — empty — with the end of its directory. */
    private static function isZip(string $path): bool
    {
        $start = @file_get_contents($path, false, null, 0, 4);

        return $start === "PK\x03\x04" || $start === "PK\x05\x06";
    }

    /**
     * @throws ImportRefused
     */
    private function json(string $path): string
    {
        $size = @filesize($path);
        $json = $size === false || $size > ProductsFile::MAX_BYTES ? false : @file_get_contents($path);

        if (! is_string($json)) {
            throw new ImportRefused([['at' => 'file', 'problem' => 'a JSON file of at most 20 MB, or a zip holding it']]);
        }

        return $json;
    }
}
