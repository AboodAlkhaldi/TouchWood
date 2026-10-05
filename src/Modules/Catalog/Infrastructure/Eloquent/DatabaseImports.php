<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Import\FileItem;
use Modules\Catalog\Application\Import\FileProduct;
use Modules\Catalog\Application\Import\ImportHeader;
use Modules\Catalog\Application\Import\ImportName;
use Modules\Catalog\Application\Import\ImportNameRow;
use Modules\Catalog\Application\Import\ImportProduct;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Application\Import\ImportSummary;
use Modules\Catalog\Application\Import\StoreFillItem;
use Modules\Catalog\Public\Enums\AttributeKind;
use stdClass;

final readonly class DatabaseImports implements Imports
{
    private const string IMPORTS = 'catalog.imports';

    private const string NAMES = 'catalog.import_names';

    private const string PRODUCTS = 'catalog.import_products';

    private const string CODES = 'catalog.product_codes';

    private const string ITEMS = 'catalog.store_fill_items';

    /** Rows per insert, well inside PostgreSQL's 65,535 parameters. */
    private const int CHUNK = 500;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function nextId(): string
    {
        return strtolower((string) Str::ulid());
    }

    public function addProductsImport(string $id, string $fileName, ?string $archive, ?string $uploadedBy, array $names, array $products, array $conflicts): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::IMPORTS)->insert([
            'id' => $id,
            'kind' => 'PRODUCTS',
            'store_id' => null,
            'file_name' => $fileName,
            'archive' => $archive,
            'state' => 'DECIDING',
            'uploaded_by' => $uploadedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->insertNames($id, $names);

        foreach (array_chunk($products, self::CHUNK) as $chunk) {
            $this->db->table(self::PRODUCTS)->insert(array_map(static fn (FileProduct $product): array => [
                'id' => strtolower((string) Str::ulid()),
                'import_id' => $id,
                'number' => $product->number,
                'data' => json_encode($product->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'codes' => '{'.implode(',', $product->codes()).'}',
                'conflict_product_id' => $conflicts[$product->number] ?? null,
                'state' => 'WAITING',
            ], $chunk));
        }
    }

    public function addStoreFill(string $id, string $storeId, string $fileName, ?string $uploadedBy, array $items): void
    {
        $now = CarbonImmutable::now();

        $this->db->table(self::IMPORTS)->insert([
            'id' => $id,
            'kind' => ImportHeader::STORE_FILL,
            'store_id' => $storeId,
            'file_name' => $fileName,
            'archive' => null,
            'state' => ImportHeader::OPEN,
            'uploaded_by' => $uploadedBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (array_chunk($items, self::CHUNK) as $chunk) {
            $this->db->table(self::ITEMS)->insert(array_map(static fn (FileItem $item): array => [
                'id' => strtolower((string) Str::ulid()),
                'import_id' => $id,
                'number' => $item->number,
                'code' => $item->code,
                'price' => $item->price,
                'stock' => $item->stock,
                'state' => StoreFillItem::OPEN,
            ], $chunk));
        }
    }

    public function items(string $importId): array
    {
        return array_values($this->db->table(self::ITEMS)->where('import_id', $importId)->orderBy('number')->get()->map(static fn (stdClass $row): StoreFillItem => new StoreFillItem(
            (string) $row->id,
            (int) $row->number,
            (string) $row->code,
            (string) $row->price,
            $row->stock === null ? null : (int) $row->stock,
            (string) $row->state,
        ))->all());
    }

    public function saveItem(StoreFillItem $item): void
    {
        $this->db->table(self::ITEMS)->where('id', $item->id)->update(['code' => $item->code, 'state' => $item->state]);
    }

    public function header(string $importId): ?ImportHeader
    {
        return $this->read($importId, false);
    }

    public function lock(string $importId): ?ImportHeader
    {
        return $this->read($importId, true);
    }

    private function read(string $importId, bool $lock): ?ImportHeader
    {
        if (! Ulids::valid($importId)) {
            return null;
        }

        $row = $this->db->table(self::IMPORTS)->where('id', strtolower($importId))->when($lock, fn ($query) => $query->lockForUpdate())->first();

        return $row === null ? null : new ImportHeader(
            (string) $row->id,
            (string) $row->kind,
            self::text($row->store_id),
            (string) $row->file_name,
            self::text($row->archive),
            (string) $row->state,
            self::text($row->failure),
            self::text($row->uploaded_by),
            CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
        );
    }

    public function summaries(string $kind, ?string $storeId, int $page, int $perPage): array
    {
        $count = $kind === ImportHeader::PRODUCTS
            ? '(SELECT count(*) FROM '.self::PRODUCTS.' AS p WHERE p.import_id = i.id)'
            : '(SELECT count(*) FROM '.self::ITEMS.' AS t WHERE t.import_id = i.id)';
        $query = $this->db->table(self::IMPORTS.' as i')->where('i.kind', $kind)->when($storeId !== null, fn ($query) => $query->where('i.store_id', $storeId));
        $total = $query->count();
        $rows = $query->orderByDesc('i.created_at')->orderByDesc('i.id')->forPage($page, $perPage)
            ->get(['i.id', 'i.file_name', 'i.state', 'i.uploaded_by', 'i.created_at', $this->db->raw("{$count} AS count")]);

        return [array_values($rows->map(static fn (stdClass $row): ImportSummary => new ImportSummary(
            (string) $row->id,
            (string) $row->file_name,
            (string) $row->state,
            (int) $row->count,
            self::text($row->uploaded_by),
            CarbonImmutable::parse((string) $row->created_at)->toIso8601String(),
        ))->all()), $total];
    }

    public function names(string $importId): array
    {
        // Ids are ULIDs made one after another in one process, so their order is the file's.
        return array_values($this->db->table(self::NAMES)->where('import_id', $importId)->orderBy('id')->get()->map(static fn (stdClass $row): ImportName => new ImportName(
            (string) $row->id,
            (string) $row->kind,
            (string) $row->written,
            (string) $row->key,
            self::text($row->attribute),
            $row->attribute_kind === null ? null : AttributeKind::from((string) $row->attribute_kind),
            self::text($row->decision),
            self::text($row->target_id),
            self::text($row->name_ar),
            self::text($row->name_en),
            (int) $row->products,
        ))->all());
    }

    public function decideName(ImportName $name): void
    {
        $this->db->table(self::NAMES)->where('id', $name->id)->update([
            'decision' => $name->decision,
            'target_id' => $name->targetId,
            'name_ar' => $name->nameAr,
            'name_en' => $name->nameEn,
        ]);
    }

    public function products(string $importId): array
    {
        return array_values($this->db->table(self::PRODUCTS)->where('import_id', $importId)->orderBy('number')->get()->map(static function (stdClass $row): ImportProduct {
            /** @var array<string, mixed> $data */
            $data = json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR);
            /** @var array<string, string>|null $newCodes */
            $newCodes = $row->new_codes === null ? null : json_decode((string) $row->new_codes, true, 512, JSON_THROW_ON_ERROR);
            /** @var array<string, mixed>|null $edited */
            $edited = $row->edited === null ? null : json_decode((string) $row->edited, true, 512, JSON_THROW_ON_ERROR);

            return new ImportProduct(
                (string) $row->id,
                (int) $row->number,
                FileProduct::fromArray($data),
                self::textArray((string) $row->codes),
                self::text($row->conflict_product_id),
                self::text($row->decision),
                $newCodes,
                self::text($row->product_id),
                (string) $row->state,
                $edited === null ? null : FileProduct::fromArray($edited),
            );
        })->all());
    }

    public function saveEdits(array $products): void
    {
        foreach (array_chunk($products, self::CHUNK) as $chunk) {
            $values = implode(', ', array_fill(0, count($chunk), '(?, ?::jsonb)'));
            $bindings = [];

            foreach ($chunk as $product) {
                $bindings[] = $product->id;
                $bindings[] = json_encode($product->effective()->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            }

            $this->db->update('UPDATE '.self::PRODUCTS." AS p SET edited = v.edited FROM (VALUES {$values}) AS v(id, edited) WHERE p.id = v.id", $bindings);
        }
    }

    public function replaceNames(string $importId, array $names): void
    {
        $kept = [];

        foreach ($this->names($importId) as $name) {
            $kept[$name->kind.' '.$name->key] = $name->id;
        }

        $new = [];

        foreach ($names as $name) {
            $id = $kept[$name->kind.' '.$name->key] ?? null;

            if ($id === null) {
                $new[] = $name;
            } else {
                $this->db->table(self::NAMES)->where('id', $id)->update(['products' => count($name->products)]);
                unset($kept[$name->kind.' '.$name->key]);
            }
        }

        if ($kept !== []) {
            $this->db->table(self::NAMES)->whereIn('id', array_values($kept))->delete();
        }

        $this->insertNames($importId, $new);
    }

    public function decideCode(ImportProduct $product): void
    {
        $this->db->table(self::PRODUCTS)->where('id', $product->id)->update([
            'decision' => $product->decision,
            'new_codes' => $product->newCodes === null ? null : json_encode((object) $product->newCodes, JSON_THROW_ON_ERROR),
        ]);
    }

    public function reopen(string $importId): void
    {
        $this->setState($importId, ImportHeader::DECIDING, null);
    }

    private function setState(string $importId, string $state, ?string $failure): void
    {
        $this->db->table(self::IMPORTS)->where('id', $importId)->update(['state' => $state, 'failure' => $failure, 'updated_at' => CarbonImmutable::now()]);
    }

    public function recordConflicts(array $holders): void
    {
        foreach ($this->db->table(self::PRODUCTS)->whereIn('id', array_keys($holders))->get(['id', 'conflict_product_id']) as $row) {
            $holder = $holders[(string) $row->id];

            if ($holder !== self::text($row->conflict_product_id)) {
                $this->db->table(self::PRODUCTS)->where('id', $row->id)->update(['conflict_product_id' => $holder, 'decision' => null, 'new_codes' => null]);
            }
        }
    }

    public function start(string $importId): void
    {
        $this->setState($importId, ImportHeader::BRINGING_IN, null);
    }

    public function recordResults(array $results): void
    {
        foreach ($results as $id => $result) {
            $this->db->table(self::PRODUCTS)->where('id', $id)->update($result);
        }
    }

    public function finish(string $importId): void
    {
        $this->db->table(self::IMPORTS)->where('id', $importId)->update(['state' => ImportHeader::IN, 'failure' => null, 'archive' => null, 'updated_at' => CarbonImmutable::now()]);
    }

    public function fail(string $importId, string $failure): void
    {
        $this->setState($importId, ImportHeader::FAILED, mb_substr($failure, 0, 2000));
    }

    public function codeHolders(array $codes): array
    {
        $holders = [];

        // Codes are digits only (§1.2), so the array literal needs no quoting.
        foreach (array_chunk(array_values(array_unique($codes)), 5000) as $chunk) {
            $rows = $this->db->table(self::CODES)
                ->whereRaw('code = ANY(?::text[])', ['{'.implode(',', $chunk).'}'])
                ->get(['code', 'product_id']);

            foreach ($rows as $row) {
                $holders[(string) $row->code] = (string) $row->product_id;
            }
        }

        return $holders;
    }

    /**
     * @param  list<ImportNameRow>  $names
     */
    private function insertNames(string $importId, array $names): void
    {
        foreach (array_chunk($names, self::CHUNK) as $chunk) {
            $this->db->table(self::NAMES)->insert(array_map(static fn (ImportNameRow $name): array => [
                'id' => strtolower((string) Str::ulid()),
                'import_id' => $importId,
                'kind' => $name->kind,
                'written' => $name->written,
                'key' => $name->key,
                'attribute' => $name->attribute,
                'attribute_kind' => $name->attributeKind?->value,
                'products' => count($name->products),
            ], $chunk));
        }
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    /**
     * A text[] as PostgreSQL sends it: `{a,b}`. Codes are digits, so nothing in it is quoted.
     *
     * @return list<string>
     */
    private static function textArray(string $value): array
    {
        $inner = trim($value, '{}');

        return $inner === '' ? [] : explode(',', $inner);
    }
}
