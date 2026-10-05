<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DecideImportCodes;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Domain\Exception\CodeTaken;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **Deciding the products whose codes the catalog already has** (catalog.md §1.12, page part 2;
 * amendment 6(d)): `catalog.import.run`. Each **updates** that product, **replaces** it whole, is
 * **skipped**, or — a typo — takes **new codes** for the codes the catalog has: each 1 to 10 digits,
 * no product's in the catalog, now or once, and no other product's of the file. Decided while the
 * import is deciding, or again after bringing it in failed. Each decision that changes something is
 * audited; the catalog changes only when the products are brought in, where the codes are asked again.
 */
final readonly class DecideImportCodesHandler
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    /** Decisions sent at once. */
    public const int MAX = 500;

    private const array DECISIONS = [ImportProduct::UPDATE, ImportProduct::REPLACE, ImportProduct::SKIP, ImportProduct::RECODE];

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private PlatformApi $platform,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws CodeTaken|ImportClosed|InvalidCatalogAttribute|ListItemNotFound|Unauthorized
     */
    public function handle(DecideImportCodes $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $decisions = self::read($command->decisions);

        $this->db->transaction(function () use ($command, $decisions): void {
            $import = $this->imports->lock($command->importId);

            if ($import === null || $import->kind !== ImportHeader::PRODUCTS) {
                throw new ListItemNotFound($command->importId);
            }

            if (! $import->isDeciding()) {
                throw new ImportClosed;
            }

            $products = [];

            foreach ($this->imports->products($import->id) as $product) {
                $products[$product->id] = $product;
            }

            $before = $products;

            foreach ($decisions as $index => $decision) {
                $at = "decisions.{$index}";
                $product = $products[strtolower($decision['product_id'])] ?? throw new ListItemNotFound($decision['product_id']);

                if ($product->conflictProductId === null) {
                    throw new InvalidCatalogAttribute("{$at}.product_id", 'a product whose code the catalog has');
                }

                $newCodes = $decision['decision'] === ImportProduct::RECODE
                    ? $this->newCodes($product, $decision['new_codes'], $products, "{$at}.new_codes")
                    : ($decision['new_codes'] === null ? null : throw new InvalidCatalogAttribute("{$at}.new_codes", 'only with RECODE'));
                $products[$product->id] = $product->decided($decision['decision'], $newCodes);
            }

            $entries = [];

            foreach ($before as $id => $was) {
                if ($products[$id]->snapshot() !== $was->snapshot()) {
                    $this->imports->decideCode($products[$id]);
                    $entries[] = self::audit($was, $products[$id]);
                }
            }

            if ($entries !== [] && $import->state === ImportHeader::FAILED) {
                $this->imports->reopen($import->id);
            }

            foreach ($entries as $entry) {
                $this->platform->recordAudit($entry);
            }
        }, 3);
    }

    /**
     * A new code for each code the catalog has, and only for those.
     *
     * @param  array<string, string>|null  $given
     * @param  array<string, ImportProduct>  $products
     * @return array<string, string>
     *
     * @throws CodeTaken|InvalidCatalogAttribute
     */
    private function newCodes(ImportProduct $product, ?array $given, array $products, string $at): array
    {
        $held = array_map('strval', array_keys($this->imports->codeHolders($product->codes)));
        sort($held);
        $from = array_map('strval', array_keys($given ?? []));
        sort($from);

        if ($given === null || $held === [] || $from !== $held) {
            throw new InvalidCatalogAttribute($at, 'a new code for each code the catalog has: '.implode(', ', $held));
        }

        $taken = [];

        foreach ($products as $other) {
            if ($other->id !== $product->id) {
                foreach ($other->codesComingIn() as $code) {
                    $taken[$code] = true;
                }
            }
        }

        $newCodes = [];

        foreach ($given as $code => $new) {
            try {
                $new = ProductCode::of($new)->value;
            } catch (InvalidCatalogAttribute $error) {
                throw new InvalidCatalogAttribute("{$at}.{$code}", $error->reason);
            }

            if (isset($taken[$new])) {
                throw new InvalidCatalogAttribute("{$at}.{$code}", 'a code no other product of the file has');
            }

            $newCodes[(string) $code] = $new;
        }

        $holders = $this->imports->codeHolders(array_values($newCodes));

        if ($holders !== []) {
            throw new CodeTaken((string) array_key_first($holders));
        }

        return $newCodes;
    }

    private static function audit(ImportProduct $before, ImportProduct $now): AuditEntryDto
    {
        $was = [];

        foreach ($now->snapshot() as $column => $value) {
            if ($before->snapshot()[$column] !== $value) {
                $was[$column] = $before->snapshot()[$column];
            }
        }

        return ListAudit::changed('import_product', 'decided', $now->id, $was, $now->snapshot()) ?? throw new LogicException('A decision that changed nothing.');
    }

    /**
     * @param  array<array-key, mixed>  $decisions
     * @return list<array{product_id: string, decision: string, new_codes: array<string, string>|null}>
     *
     * @throws InvalidCatalogAttribute
     */
    private static function read(array $decisions): array
    {
        if ($decisions === [] || count($decisions) > self::MAX) {
            throw new InvalidCatalogAttribute('decisions', 'from 1 to '.self::MAX.' at once');
        }

        $read = [];
        $seen = [];

        foreach (array_values($decisions) as $index => $decision) {
            if (! is_array($decision) || ! is_string($decision['product_id'] ?? null) || ! in_array($decision['decision'] ?? null, self::DECISIONS, true)) {
                throw new InvalidCatalogAttribute("decisions.{$index}", 'a product and its decision: UPDATE, REPLACE, SKIP or RECODE');
            }

            if (isset($seen[strtolower($decision['product_id'])])) {
                throw new InvalidCatalogAttribute("decisions.{$index}", 'each product once');
            }

            $seen[strtolower($decision['product_id'])] = true;
            $read[] = ['product_id' => $decision['product_id'], 'decision' => (string) $decision['decision'], 'new_codes' => self::codes($decision['new_codes'] ?? null, $index)];
        }

        return $read;
    }

    /**
     * @return array<string, string>|null
     */
    private static function codes(mixed $raw, int $index): ?array
    {
        if ($raw === null) {
            return null;
        }

        if (! is_array($raw) || $raw === [] || count($raw) > 100) {
            throw new InvalidCatalogAttribute("decisions.{$index}.new_codes", 'each code the catalog has, and its new one');
        }

        $codes = [];

        foreach ($raw as $from => $to) {
            $codes[(string) $from] = is_string($to) ? $to : throw new InvalidCatalogAttribute("decisions.{$index}.new_codes.{$from}", 'the code as text: 1 to 10 digits, in quotes');
        }

        return $codes;
    }
}
