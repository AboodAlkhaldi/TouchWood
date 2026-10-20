<?php

declare(strict_types=1);

use App\Http\AdminArea;
use Illuminate\Support\Facades\Route;
use Modules\Promotions\Presentation\Http\Controller\CouponsController;
use Modules\Promotions\Presentation\Http\Controller\GiftLevelsController;
use Modules\Promotions\Presentation\Http\Controller\SegmentsController;

/*
| Promotions' screens in the admin panel (promotions.md §4.1): the Marketing menu's Coupons, Segments
| and Gift Levels.
|
| Mounted into the area App\Http\AdminArea names, as the other modules' screens are. Who may open any of
| these is Promotions' answer, never the routing's. Named promotions.admin.*, which the panel's route
| list carries (config/ziggy.php).
*/
Route::prefix(AdminArea::PREFIX)
    ->middleware([...AdminArea::MIDDLEWARE, AdminArea::SIGNED_IN])
    ->group(function (): void {
        Route::get('coupons', [CouponsController::class, 'index'])->name('promotions.admin.coupons');
        // A new coupon, named before a coupon's own routes take the word.
        Route::get('coupons/new', [CouponsController::class, 'create'])->name('promotions.admin.coupons.new');
        Route::post('coupons', [CouponsController::class, 'store'])->name('promotions.admin.coupons.store');
        Route::get('coupons/{coupon}', [CouponsController::class, 'show'])->name('promotions.admin.coupons.show');
        Route::post('coupons/{coupon}', [CouponsController::class, 'update'])->name('promotions.admin.coupons.update');
        Route::post('coupons/{coupon}/switch', [CouponsController::class, 'switch'])->name('promotions.admin.coupons.switch');
        Route::post('coupons/{coupon}/delete', [CouponsController::class, 'destroy'])->name('promotions.admin.coupons.delete');
        Route::post('coupons/{coupon}/assign', [CouponsController::class, 'assign'])->name('promotions.admin.coupons.assign');
        Route::post('coupons/{coupon}/assignments/{customer}/remove', [CouponsController::class, 'removeAssignment'])->name('promotions.admin.coupons.assignments.remove');

        Route::get('segments', [SegmentsController::class, 'index'])->name('promotions.admin.segments');
        Route::post('segments', [SegmentsController::class, 'store'])->name('promotions.admin.segments.store');
        Route::get('segments/{segment}', [SegmentsController::class, 'show'])->name('promotions.admin.segments.show');
        Route::post('segments/{segment}', [SegmentsController::class, 'update'])->name('promotions.admin.segments.update');
        Route::post('segments/{segment}/refresh', [SegmentsController::class, 'refresh'])->name('promotions.admin.segments.refresh');
        Route::post('segments/{segment}/delete', [SegmentsController::class, 'destroy'])->name('promotions.admin.segments.delete');
        Route::post('segments/{segment}/members', [SegmentsController::class, 'members'])->name('promotions.admin.segments.members');

        Route::get('gift-levels', [GiftLevelsController::class, 'index'])->name('promotions.admin.gift_levels');
        Route::post('gift-levels', [GiftLevelsController::class, 'store'])->name('promotions.admin.gift_levels.store');
        Route::post('gift-levels/{level}', [GiftLevelsController::class, 'update'])->name('promotions.admin.gift_levels.update');
        Route::post('gift-levels/{level}/switch', [GiftLevelsController::class, 'switch'])->name('promotions.admin.gift_levels.switch');
    });
