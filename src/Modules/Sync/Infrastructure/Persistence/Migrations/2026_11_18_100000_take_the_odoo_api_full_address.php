<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
| flags-2026-11.md S6 (owner, 2026-11-18): the Provider page's address is now the API's full address, its path
| included, and the read calls it as it is; the secret only signs the provider's notices, so it is optional.
|
| - An Odoo connection saved as the account's address alone (the rule until today) gets Odoo's standard path,
|   so it keeps reading exactly what it read. Nothing is live yet: these are local and test rows.
| - `secret_encrypted` may be empty: a store that takes no notices has no secret.
*/

return new class extends Migration
{
    private const string PATH = '/json/2/product.product/read';

    public function up(): void
    {
        DB::statement("UPDATE sync.connections SET address = rtrim(address, '/') || ? WHERE provider = 'ODOO' AND address ~ '^https://[^/?#]+/?$'", [self::PATH]);
        DB::statement('ALTER TABLE sync.connections ALTER COLUMN secret_encrypted DROP NOT NULL');
    }

    public function down(): void
    {
        // A connection with no secret cannot go back to a column that needs one: it is unwired first, by hand.
        DB::statement('ALTER TABLE sync.connections ALTER COLUMN secret_encrypted SET NOT NULL');
        DB::statement("UPDATE sync.connections SET address = left(address, length(address) - length(?)) WHERE provider = 'ODOO' AND address LIKE ?", [self::PATH, '%'.self::PATH]);
    }
};
