<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\DecideImportNames;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\CatalogNames;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\ImportName;
use Modules\Catalog\Application\Import\ImportNameRow;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Import\ProductsFile;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotFound;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\NameTaken;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Catalog\Domain\ValueObject\CatalogText;
use Modules\Catalog\Public\Enums\AttributeKind;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\AuditEntryDto;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **Deciding the names a products file uses that the catalog lacks** (catalog.md §1.12, page part 1;
 * amendments 6(c), 7(a)): `catalog.import.run`. Each name **means one the catalog has** — a typo: an
 * active one of that list; an attribute doing the job the file uses it for; a value of its attribute,
 * once the catalog has that attribute or it is decided as one the catalog has —, is **created** with
 * its names in both languages — a value (under such an attribute), a category or a set; brands,
 * warranties and attributes are added in the panel first —, or is **refused**. Decided while the
 * import is deciding, or again after bringing it in failed. An attribute decided again sends the
 * values picked or created under it back to wait.
 *
 * Only the decision is kept here: the catalog changes when the products are brought in, where every
 * decision is checked again against the catalog as it is then. Each decision that changes something
 * is audited.
 */
final readonly class DecideImportNamesHandler
{
    /** Reserved: a Super Admin's (handoff §9.1). */
    public const string PERMISSION = CatalogPermissions::IMPORT_RUN;

    /** Decisions sent at once. */
    public const int MAX = 500;

    /** What the page may create; brands, warranties and attributes are added in the panel first (amendment 7(a)). */
    private const array CREATED = [ImportNameRow::VALUE, ImportNameRow::CATEGORY, ImportNameRow::SET];

    public function __construct(
        private Authorizer $authorizer,
        private Imports $imports,
        private PlatformApi $platform,
        private BrandRepository $brands,
        private CategoryRepository $categories,
        private AttributeRepository $attributes,
        private WarrantyRepository $warranties,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws BrandInactive|BrandNotFound|CategoryInactive|CategoryNotFound|ImportClosed|InvalidCatalogAttribute|ListItemInactive|ListItemNotFound|NameTaken|Unauthorized
     */
    public function handle(DecideImportNames $command): void
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

            $names = [];

            foreach ($this->imports->names($import->id) as $name) {
                $names[$name->id] = $name;
            }

            $before = $names;
            $catalog = CatalogNames::load($this->brands, $this->categories, $this->attributes, $this->warranties);
            $changed = [];

            foreach ($decisions as $index => $decision) {
                $name = $names[strtolower($decision['name_id'])] ?? throw new ListItemNotFound($decision['name_id']);
                $decided = $this->decide($name, $decision, $names, $catalog, "decisions.{$index}");
                $names[$name->id] = $decided;
                $changed[$name->id] = true;

                // The values picked or created under an attribute decided again wait for a decision again.
                if ($name->kind === ImportNameRow::ATTRIBUTE && $decided->targetId !== $name->targetId) {
                    foreach ($names as $id => $value) {
                        if ($value->kind === ImportNameRow::VALUE && in_array($value->decision, [ImportName::EXISTING, ImportName::CREATE], true) && ImportNameRow::keyOf([(string) $value->attribute]) === $name->key) {
                            $names[$id] = $value->undecided();
                            $changed[$id] = true;
                        }
                    }
                }
            }

            $entries = [];

            foreach ($before as $id => $was) {
                if (isset($changed[$id]) && $names[$id]->snapshot() !== $was->snapshot()) {
                    $this->imports->decideName($names[$id]);
                    $entries[] = self::audit($was, $names[$id]);
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
     * @param  array{name_id: string, decision: string, target_id: string|null, name_ar: string|null, name_en: string|null}  $decision
     * @param  array<string, ImportName>  $names
     */
    private function decide(ImportName $name, array $decision, array $names, CatalogNames $catalog, string $at): ImportName
    {
        return match ($decision['decision']) {
            ImportName::EXISTING => $name->decided(ImportName::EXISTING, $this->target($name, $decision['target_id'] ?? throw new InvalidCatalogAttribute("{$at}.target_id", 'required'), $names, $catalog, $at), null, null),
            ImportName::CREATE => in_array($name->kind, self::CREATED, true)
                ? $this->created($name, $decision, $names, $catalog, $at)
                : throw new InvalidCatalogAttribute("{$at}.decision", 'EXISTING or REFUSE: brands, warranties and attributes are added in the panel first'),
            ImportName::REFUSE => $name->decided(ImportName::REFUSE, null, null, null),
            default => throw new InvalidCatalogAttribute("{$at}.decision", 'EXISTING, CREATE or REFUSE'),
        };
    }

    /**
     * The one it means: in that list, active, doing the job the file needs of it.
     *
     * @param  array<string, ImportName>  $names
     */
    private function target(ImportName $name, string $targetId, array $names, CatalogNames $catalog, string $at): string
    {
        switch ($name->kind) {
            case ImportNameRow::BRAND:
                $brand = $this->brands->find($targetId) ?? throw new BrandNotFound($targetId);

                return $brand->isActive() ? $brand->id() : throw new BrandInactive;
            case ImportNameRow::CATEGORY:
                $category = $this->categories->find($targetId) ?? throw new CategoryNotFound($targetId);

                return $category->isActive() ? $category->id() : throw new CategoryInactive;
            case ImportNameRow::WARRANTY:
                $warranty = $this->warranties->find($targetId) ?? throw new ListItemNotFound($targetId);

                return $warranty->isActive() ? $warranty->id() : throw new ListItemInactive;
            case ImportNameRow::SET:
                $set = $this->attributes->findSet($targetId) ?? throw new ListItemNotFound($targetId);

                return $set->isActive() ? $set->id() : throw new ListItemInactive;
            case ImportNameRow::ATTRIBUTE:
                $attribute = $this->attributes->find($targetId) ?? throw new ListItemNotFound($targetId);

                if (! $attribute->isActive()) {
                    throw new ListItemInactive;
                }

                return $attribute->kind() === $name->attributeKind ? $attribute->id() : throw new InvalidCatalogAttribute("{$at}.target_id", 'an attribute for '.self::place($name->attributeKind));
            default:
                $value = $this->attributes->findValue($targetId) ?? throw new ListItemNotFound($targetId);

                if (! $value->isActive()) {
                    throw new ListItemInactive;
                }

                $attributeId = $this->attributeOf($name, $names, $catalog);

                return $attributeId !== null && $value->attributeId() === $attributeId ? $value->id() : throw new InvalidCatalogAttribute("{$at}.target_id", "a value of {$name->attribute}, once it is one the catalog has");
        }
    }

    /**
     * Its wording, as the lists hold it: both languages, each one line.
     *
     * @param  array{name_id: string, decision: string, target_id: string|null, name_ar: string|null, name_en: string|null}  $decision
     * @param  array<string, ImportName>  $names
     */
    private function created(ImportName $name, array $decision, array $names, CatalogNames $catalog, string $at): ImportName
    {
        $nameAr = CatalogText::oneLine("{$at}.name_ar", (string) $decision['name_ar'], ProductsFile::NAME_MAX);
        $nameEn = CatalogText::oneLine("{$at}.name_en", (string) $decision['name_en'], ProductsFile::NAME_MAX);

        // A value is made under an attribute the catalog has, each once (§1.7): one it has already is
        // chosen, not made again.
        if ($name->kind === ImportNameRow::VALUE) {
            $attributeId = $this->attributeOf($name, $names, $catalog)
                ?? throw new InvalidCatalogAttribute("{$at}.decision", "a value of {$name->attribute} is created once {$name->attribute} is one the catalog has");

            if (($catalog->value($attributeId, $nameAr) ?? $catalog->value($attributeId, $nameEn)) !== null) {
                throw new NameTaken('name');
            }
        }

        return $name->decided(ImportName::CREATE, null, $nameAr, $nameEn);
    }

    /**
     * A value's attribute in the catalog: the catalog's of that name, or the one its name is decided as.
     *
     * @param  array<string, ImportName>  $names
     */
    private function attributeOf(ImportName $value, array $names, CatalogNames $catalog): ?string
    {
        $existing = $catalog->attribute((string) $value->attribute);

        if ($existing !== null) {
            return $existing->id();
        }

        $key = ImportNameRow::keyOf([(string) $value->attribute]);

        foreach ($names as $name) {
            if ($name->kind === ImportNameRow::ATTRIBUTE && $name->key === $key) {
                return $name->decision === ImportName::EXISTING ? $name->targetId : null;
            }
        }

        return null;
    }

    private static function audit(ImportName $before, ImportName $now): AuditEntryDto
    {
        $was = [];

        foreach ($now->snapshot() as $column => $value) {
            if ($before->snapshot()[$column] !== $value) {
                $was[$column] = $before->snapshot()[$column];
            }
        }

        return ListAudit::changed('import_name', 'decided', $now->id, $was, $now->snapshot()) ?? throw new LogicException('A decision that changed nothing.');
    }

    /**
     * @param  array<array-key, mixed>  $decisions
     * @return list<array{name_id: string, decision: string, target_id: string|null, name_ar: string|null, name_en: string|null}>
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
            if (! is_array($decision) || ! is_string($decision['name_id'] ?? null) || ! is_string($decision['decision'] ?? null)) {
                throw new InvalidCatalogAttribute("decisions.{$index}", 'a name and its decision');
            }

            if (isset($seen[strtolower($decision['name_id'])])) {
                throw new InvalidCatalogAttribute("decisions.{$index}", 'each name once');
            }

            $seen[strtolower($decision['name_id'])] = true;
            $read[] = [
                'name_id' => $decision['name_id'],
                'decision' => $decision['decision'],
                'target_id' => self::text($decision, 'target_id', $index),
                'name_ar' => self::text($decision, 'name_ar', $index),
                'name_en' => self::text($decision, 'name_en', $index),
            ];
        }

        return $read;
    }

    /**
     * @param  array<array-key, mixed>  $decision
     */
    private static function text(array $decision, string $field, int $index): ?string
    {
        $value = $decision[$field] ?? null;

        return $value === null || is_string($value) ? $value : throw new InvalidCatalogAttribute("decisions.{$index}.{$field}", 'text');
    }

    private static function place(?AttributeKind $kind): string
    {
        return match ($kind) {
            AttributeKind::Variant => 'values',
            AttributeKind::Filterable => 'filters',
            default => 'details',
        };
    }
}
