<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\Own;

use Modules\Sales\Application\SalesPermissions;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/** Who reads their own orders (sales.md §3: `sales.order.view_own`, every store): a signed-in customer. */
final readonly class OwnReader
{
    public function __construct(
        private Authorizer $authorizer,
        private ActorContext $actors,
    ) {}

    /**
     * @return string the customer
     *
     * @throws Unauthorized
     */
    public function authorize(): string
    {
        $actor = $this->actors->current();

        if ($actor->type !== ActorType::Customer || $actor->id === null) {
            throw new Unauthorized(SalesPermissions::ORDER_VIEW_OWN);
        }

        $this->authorizer->authorize(SalesPermissions::ORDER_VIEW_OWN, PermissionScope::global());

        return strtolower($actor->id);
    }

    /** An order number as the address gives it: upper case, at most 24 characters — anything else none. */
    public static function number(?string $number): ?string
    {
        $number = $number === null ? '' : strtoupper(trim($number));

        return $number !== '' && strlen($number) <= 24 ? $number : null;
    }
}
