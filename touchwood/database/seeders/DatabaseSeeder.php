<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlatformSeeder::class);
        // The default brand (catalog.md amendment 1(c)).
        $this->call(CatalogSeeder::class);
    }
}
