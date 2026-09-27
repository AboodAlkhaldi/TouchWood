<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| b2b.md §1.3, §5: the company types and the document types, both tables staff manage.
|
| Every rule here is also a rule in code (TypeName, TypePosition, the repositories' nameTaken), which
| refuses first; these are the backstop. The unique names are the one rule whose code half arrives
| with the screens that add and rename types (step 4): until then only this migration writes rows.
*/

return new class extends Migration
{
    /**
     * The six the owner listed on 2026-09-27, in their order. "Other" is not among them: it is not
     * a row, and the company describes it in its own words.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array COMPANY_TYPES = [
        ['مؤسسة فردية', 'Sole Proprietorship / Individual Establishment'],
        ['شركة ذات مسؤولية محدودة', 'Limited Liability Company'],
        ['شركة مساهمة', 'Joint Stock Company'],
        ['شركة مساهمة مبسطة', 'Simplified Joint Stock Company'],
        ['شركة تضامن', 'General Partnership'],
        ['شركة توصية بسيطة', 'Limited Partnership'],
    ];

    /**
     * The three handoff §8.1 names, required and shipped required (owner, 2026-09-19); the Arabic
     * names confirmed by the owner on 2026-09-27.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const array DOCUMENT_TYPES = [
        ['شهادة ضريبة القيمة المضافة', 'VAT certificate'],
        ['شهادة السجل التجاري', 'Commercial registration certificate'],
        ['هوية المفوّض بالتوقيع', 'Authorised signatory ID'],
    ];

    public function up(): void
    {
        foreach (['company_types', 'document_types'] as $table) {
            Schema::create("b2b.{$table}", function (Blueprint $blueprint) use ($table) {
                $blueprint->ulid('id')->primary();
                $blueprint->string('name_ar', 100);
                $blueprint->string('name_en', 100);
                $blueprint->integer('position');
                $blueprint->boolean('is_active')->default(true);

                if ($table === 'document_types') {
                    $blueprint->boolean('is_required')->default(false);
                }

                $blueprint->timestampsTz();
            });

            foreach (['name_ar', 'name_en'] as $name) {
                // Present, and on one line: a name is a choice in a dropdown.
                DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$table}_{$name}_present CHECK (btrim({$name}) <> '')");
                DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$table}_{$name}_one_line CHECK ({$name} !~ '[[:cntrl:]]')");
                // Unique ignoring case, in each language (owner, 2026-09-27).
                DB::statement("CREATE UNIQUE INDEX {$table}_{$name}_unique ON b2b.{$table} (lower({$name}))");
            }

            DB::statement("ALTER TABLE b2b.{$table} ADD CONSTRAINT {$table}_position_range CHECK (position BETWEEN 0 AND 10000)");
        }

        $this->insert('company_types', self::COMPANY_TYPES, []);
        $this->insert('document_types', self::DOCUMENT_TYPES, ['is_required' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('b2b.document_types');
        Schema::dropIfExists('b2b.company_types');
    }

    /**
     * @param  list<array{0: string, 1: string}>  $names
     * @param  array<string, bool>  $extra
     */
    private function insert(string $table, array $names, array $extra): void
    {
        $now = CarbonImmutable::now();

        foreach ($names as $index => [$ar, $en]) {
            DB::table("b2b.{$table}")->insert([
                'id' => strtolower((string) Str::ulid()),
                'name_ar' => $ar,
                'name_en' => $en,
                'position' => $index + 1,
                'is_active' => true,
                ...$extra,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
