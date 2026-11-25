<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| One-off, for the catalogue reload of 2026-11 (owner, 2026-11-25): every product goes, permanently, before the products
| of the 2027 price catalogues come in. It refuses while any order exists (an order keeps its lines' products), shows
| what will go, asks for the words typed back, then deletes in one transaction: first what refuses a product's delete
| (reviews, questions, ratings, stock holds, links between products) and the lists that name products without a key
| (a project's or a post's products, packing sizes, old store files), then the products, whose sizes, codes, photos' links, prices, stock, store switches, cart lines and wishlist items go
| with them by their keys. Brands, categories, attributes, values, warranties and the media library stay, and so do the
| append-only ledgers (stock movements, Odoo read reports): history, refusing any delete by design. A coupon or a
| gift that names a product refuses it: change those in the panel first.
*/
Artisan::command('touchwood:remove-all-products {--yes : Do not ask (tests only)}', function () {
    $count = static fn (string $table): int => DB::table($table)->count();
    if ($count('sales.orders') > 0) {
        $this->error('Refused: the shop has orders. An order keeps its products; nothing was deleted.');

        return 1;
    }
    $named = DB::table('promotions.coupon_rules')->whereNotNull('product_id')->count() + $count('promotions.gift_levels');
    if ($named > 0) {
        $this->error("Refused: {$named} coupon rules or gift levels name a product. Change them in the panel first; nothing was deleted.");

        return 1;
    }
    $before = ['products' => 'catalog.products', 'sizes (codes)' => 'catalog.variants', 'reviews' => 'feedback.reviews',
        'questions' => 'feedback.questions', 'wishlist items' => 'feedback.wishlist_items', 'cart lines' => 'sales.cart_lines',
        'stock holds' => 'inventory.hold_lines'];
    $this->table(['What', 'Now'], array_map(static fn (string $label, string $table): array => [$label, $count($table)], array_keys($before), $before));
    if (! $this->option('yes') && $this->ask('Type REMOVE ALL PRODUCTS to delete every product for good') !== 'REMOVE ALL PRODUCTS') {
        $this->warn('Not confirmed; nothing was deleted.');

        return 1;
    }
    DB::transaction(static function (): void {
        foreach (['feedback.reviews', 'feedback.questions', 'feedback.product_ratings', 'inventory.hold_lines', 'catalog.product_relations',
            'content.blog_post_products', 'content.project_products', 'shipping.variant_packing', 'catalog.store_fill_items',
            'sales.order_ticks', 'catalog.products'] as $table) {
            DB::table($table)->delete();
        }
    });
    $this->table(['What', 'After'], array_map(static fn (string $label, string $table): array => [$label, $count($table)], array_keys($before), $before));
    $this->info('Every product was deleted. Next: import the new products, then each store file.');

    return 0;
})->purpose('One-off: delete every product permanently (catalogue reload, owner 2026-11-25)');
