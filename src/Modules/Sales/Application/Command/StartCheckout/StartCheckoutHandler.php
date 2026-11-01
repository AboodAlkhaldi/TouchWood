<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\StartCheckout;

use InvalidArgumentException;
use Modules\Sales\Application\Quote\QuoteBuilder;
use Modules\Sales\Application\Quote\QuoteView;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\AddressInOtherStore;
use Modules\Sales\Domain\Exception\AddressNotUsable;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\MayNotOrder;
use Modules\Sales\Domain\Exception\NotForYourAccount;
use Modules\Sales\Domain\Exception\NotOrderable;
use Modules\Sales\Domain\Exception\QuoteExpired;
use Modules\Sales\Domain\Exception\WayToPayNotOffered;
use Modules\Sales\Domain\Model\Quote;
use Modules\Sales\Domain\Repository\Quotes;
use Modules\Sales\Public\Enums\WayToPay;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * Checkout (sales.md §1.2): a signed-in customer's cart in this store, quoted in full and kept, valid
 * for 15 minutes and never past the prices' own end. **The client never sends an amount** — placing
 * the order sends only the quote's id. A guest signs in first.
 */
final readonly class StartCheckoutHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_PLACE;

    /** The most points one may name: what the quote's column holds. */
    public const int MOST_POINTS = 2_147_483_647;

    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
        private Quotes $quotes,
        private QuoteBuilder $builder,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|MayNotOrder|NotForYourAccount|NotOrderable|AddressNotUsable|AddressInOtherStore|WayToPayNotOffered|QuoteExpired
     */
    public function handle(StartCheckout $command): QuoteView
    {
        try {
            $store = StoreId::fromString($command->storeId);
        } catch (InvalidArgumentException) {
            throw new InvalidSalesAttribute('store', 'not a store id');
        }

        $this->authorizer->authorize(self::PERMISSION, PermissionScope::store($store));
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(self::PERMISSION);
        }

        $customerId = strtolower($actor->id);
        $wayToPay = WayToPay::tryFrom(strtoupper(trim($command->wayToPay))) ?? throw new InvalidSalesAttribute('way_to_pay', 'not a way to pay');

        if ($command->pointsAsked !== null && (! $command->usePoints || $command->pointsAsked < 1 || $command->pointsAsked > self::MOST_POINTS)) {
            throw new InvalidSalesAttribute('points', 'a number of points from 1 to '.self::MOST_POINTS.', when using points');
        }

        $view = $this->builder->build($store, $customerId, trim($command->addressId), $command->usePoints, $command->pointsAsked, $wayToPay, $command->carrierId === null || trim($command->carrierId) === '' ? null : trim($command->carrierId));

        $this->quotes->add(new Quote(
            $view->id,
            $view->storeId,
            $customerId,
            $view->addressId,
            $view->couponCode,
            $view->usePoints,
            $view->pointsAsked,
            $view->wayToPay->value,
            $view->toArray(),
            $view->expiresAt,
            $view->createdAt,
        ));

        return $view;
    }
}
