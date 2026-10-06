<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\CorrectVariantCode;

use LogicException;
use Modules\Catalog\Application\Audit\ListAudit;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Events\ProductEvents;
use Modules\Catalog\Application\Lists\SharedListChange;
use Modules\Catalog\Application\Products\ProductAccess;
use Modules\Catalog\Application\Products\VariantInput;
use Modules\Catalog\Domain\Exception\CodeTaken;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\VariantNotFound;
use Modules\Catalog\Domain\Model\Variant;
use Modules\Catalog\Domain\Repository\ListLocks;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Domain\ValueObject\ProductCode;
use Shared\Application\Unauthorized;

/**
 * **Correcting a mistyped code** (catalog.md §1.2, §3): its own permission,
 * `catalog.variant.correct_code`, as the product's shared data. **Every variant of the product
 * carrying the code takes the new one** (amendment 3(e)); the new code is one the product holds or no
 * other product holds or held. **The mistyped code stays with the product** — a code it ever held is
 * never given to another while it exists — except for a product never ready, a draft archived or
 * not, where a code given up is free again (amendment 3(c), (m)). Each variant renamed is audited.
 */
final readonly class CorrectVariantCodeHandler
{
    public const string PERMISSION = CatalogPermissions::VARIANT_CORRECT_CODE;

    public function __construct(
        private ProductAccess $access,
        private SharedListChange $change,
        private ProductRepository $products,
        private VariantRepository $variants,
        private VariantInput $input,
        private ProductEvents $events,
    ) {}

    /**
     * @throws CodeTaken|InvalidCatalogAttribute|Unauthorized|VariantNotFound
     */
    public function handle(CorrectVariantCode $command): void
    {
        $this->access->authorize(self::PERMISSION);
        $code = ProductCode::of($command->code);

        $this->change->run(ListLocks::PRODUCTS, function () use ($command, $code): array {
            $variant = $this->variants->byId($command->variantId) ?? throw new VariantNotFound($command->variantId);
            $product = $this->products->byId($variant->productId()) ?? throw new LogicException('A variant without its product.');
            $this->access->authorizeFor(self::PERMISSION, $product->id());
            $old = $variant->code();

            if ($code->equals($old)) {
                return [null, []];
            }

            $this->input->freeCode($product, $code);
            $renamed = array_values(array_filter(
                $this->variants->ofProduct($product->id()),
                static fn (Variant $sibling): bool => $sibling->code()->equals($old),
            ));

            $this->products->holdCode($product->id(), $code->value);
            $this->variants->renameCode($product->id(), $old->value, $code->value);

            foreach ($renamed as $sibling) {
                $this->events->codeCorrected($product, $sibling->id());
            }

            if (! $product->hasBeenReady()) {
                $this->products->releaseCode($product->id(), $old->value);
            }

            return [null, array_values(array_filter(array_map(
                static fn (Variant $sibling): mixed => ListAudit::changed('variant', 'code_corrected', $sibling->id(), ['code' => $old->value], ['code' => $code->value]),
                $renamed,
            )))];
        });
    }
}
