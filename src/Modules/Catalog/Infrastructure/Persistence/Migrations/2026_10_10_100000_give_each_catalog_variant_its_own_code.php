<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| catalog.md §1.2, §5.1 (owner, 2026-10-09, amendment 16(a)): every variant has its own code — two
| variants never share one, replacing amendment 3(e)'s "its variants may share it". The product still
| keeps every code its variants ever held (`product_codes`), so no other product takes one; that
| table's key already keeps a code to one product, so two variants sharing one are of one product.
|
| A product whose variants share a code, and a products file not brought in whose product does
| (P34) — its codes read as they would come in, after a "give it another code" decision, which could
| give two of them one code before this — **stop the migration, named**: nothing is changed or
| deleted for them. The code is corrected in the panel, the import brought in or discarded (amendment
| 10(b)), and the migration run again.
*/

return new class extends Migration
{
    public function up(): void
    {
        $problems = [];

        foreach (DB::select('SELECT product_id, code FROM catalog.variants GROUP BY product_id, code HAVING count(*) > 1 ORDER BY product_id, code') as $row) {
            $problems[] = "product {$row->product_id}: its variants share the code {$row->code}";
        }

        $imports = DB::select(<<<'SQL'
            SELECT i.id, i.file_name, p.number
            FROM catalog.import_products AS p
            JOIN catalog.imports AS i ON i.id = p.import_id
            WHERE i.kind = 'PRODUCTS' AND i.state <> 'IN' AND p.state <> 'REFUSED'
              AND (SELECT count(*) - count(DISTINCT COALESCE(p.new_codes->>(v->>'code'), v->>'code')) FROM jsonb_array_elements(COALESCE(p.edited, p.data)->'variants') AS v) > 0
            ORDER BY i.id, p.number
            SQL);

        foreach ($imports as $row) {
            $problems[] = "import {$row->id} ({$row->file_name}), product {$row->number}: its variants share a code";
        }

        if ($problems !== []) {
            throw new RuntimeException("Every variant needs its own code (catalog.md amendment 16(a)). Correct these first, or bring in or discard the import:\n".implode("\n", $problems));
        }

        DB::statement('DROP INDEX catalog.variants_code_idx');
        DB::statement('ALTER TABLE catalog.variants ADD CONSTRAINT variants_code_unique UNIQUE (code)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE catalog.variants DROP CONSTRAINT variants_code_unique');
        DB::statement('CREATE INDEX variants_code_idx ON catalog.variants (code)');
    }
};
