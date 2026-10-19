<?php

declare(strict_types=1);

namespace Modules\Sales\Application;

use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionAudience;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;

/**
 * Sales's permissions (sales.md §3): what every guest and every customer may do in a store's shop —
 * keep a cart, place and follow their own orders — checked in the cart's or the order's store; the
 * staff jobs per store under Orders; the money settings admin-only; the system's scheduled work.
 */
final class SalesPermissions
{
    /** Keep my cart in a store, and its coupon. */
    public const string CART_MANAGE = 'sales.cart.manage';

    /** Keep a guest's cart in a store (no coupon). */
    public const string GUEST_CART_MANAGE = 'sales.guest_cart.manage';

    /** Check out and place an order in a store. */
    public const string ORDER_PLACE = 'sales.order.place';

    /** Cancel my order, ask for a return, add its photos, send a transfer document. */
    public const string ORDER_MANAGE_OWN = 'sales.order.manage_own';

    /** My orders and returns, in every store. */
    public const string ORDER_VIEW_OWN = 'sales.order.view_own';

    /** The store's orders and returns, their files, the order to print. */
    public const string ORDER_VIEW = 'sales.order.view';

    /** Approve or reject the store's orders. */
    public const string ORDER_APPROVE = 'sales.order.approve';

    /** Change an order before it ships; clear "stock was reduced". */
    public const string ORDER_EDIT = 'sales.order.edit';

    /** Cancel the store's orders. */
    public const string ORDER_CANCEL = 'sales.order.cancel';

    /** Ship, mark delivered or came back, tick "reduced in the provider", allow shipping unpaid. */
    public const string ORDER_FULFIL = 'sales.order.fulfil';

    /** Record payments and refunds, void one entered by mistake. */
    public const string PAYMENT_RECORD = 'sales.payment.record';

    /** Accept, refuse, receive, close and complete the store's returns. */
    public const string RETURN_MANAGE = 'sales.return.manage';

    /** The flat shipping fee, free shipping from, the return window — admin-only: they decide money. */
    public const string SETTINGS_UPDATE = 'sales.settings.update';

    /** Empty old carts, delete expired quotes, push the sales ranks: the system only. */
    public const string SYSTEM_RUN = 'sales.system.run';

    /**
     * @return list<PermissionDefinitionDto>
     */
    public static function definitions(): array
    {
        $customer = static fn (string $name, PermissionKind $kind = PermissionKind::PerStore): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::EveryCustomer, kind: $kind);
        $job = static fn (string $name, bool $adminOnly = false): PermissionDefinitionDto => new PermissionDefinitionDto($name, PermissionAudience::Role, adminOnly: $adminOnly, kind: PermissionKind::PerStore, group: PermissionGroup::Orders);

        return [
            $customer(self::CART_MANAGE),
            new PermissionDefinitionDto(self::GUEST_CART_MANAGE, PermissionAudience::EveryGuest, kind: PermissionKind::PerStore),
            $customer(self::ORDER_PLACE),
            $customer(self::ORDER_MANAGE_OWN),
            $customer(self::ORDER_VIEW_OWN, PermissionKind::Global),
            $job(self::ORDER_VIEW),
            $job(self::ORDER_APPROVE),
            $job(self::ORDER_EDIT),
            $job(self::ORDER_CANCEL),
            $job(self::ORDER_FULFIL),
            $job(self::PAYMENT_RECORD),
            $job(self::RETURN_MANAGE),
            $job(self::SETTINGS_UPDATE, true),
            new PermissionDefinitionDto(self::SYSTEM_RUN, PermissionAudience::Role, reserved: true, kind: PermissionKind::Global),
        ];
    }
}
