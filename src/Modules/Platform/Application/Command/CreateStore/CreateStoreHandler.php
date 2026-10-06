<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\CreateStore;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Application\Audit\StoreAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Routing\InMemoryReservedPaths;
use Modules\Platform\Domain\Exception\CurrencyNotFound;
use Modules\Platform\Domain\Exception\CurrencyTaken;
use Modules\Platform\Domain\Exception\InvalidStoreAttribute;
use Modules\Platform\Domain\Exception\StoreCodeTaken;
use Modules\Platform\Domain\Model\Store;
use Modules\Platform\Domain\Repository\CurrencyRepository;
use Modules\Platform\Domain\Repository\StoreRepository;
use Modules\Platform\Domain\ValueObject\CountryCode;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\StoreCode;
use Modules\Platform\Domain\ValueObject\TaxRate;
use Modules\Platform\Domain\ValueObject\Timezone;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Modules\Platform\Public\Events\StoreCreated;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Domain\ValueObject\StoreId;

/**
 * Opening a store (§1.1, §3): complete in one step, from the console or the stores screen (§9.7 #3),
 * created switched off. Its currency is one no store uses - one currency, one store (§9.7 #4) - or
 * one made here in the same transaction, which needs the right to create currencies too.
 */
final readonly class CreateStoreHandler
{
    public const string PERMISSION = PlatformPermissions::STORE_CREATE;

    public function __construct(
        private Authorizer $authorizer,
        private StoreRepository $stores,
        private CurrencyRepository $currencies,
        private ConnectionInterface $db,
        private Dispatcher $events,
        private StoreDirectory $directory,
        private AuditLog $auditLog,
        private InMemoryReservedPaths $reservedPaths,
        private CreateCurrencyHandler $createCurrency,
    ) {}

    public function handle(CreateStore $command): StoreId
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        if ($command->newCurrency !== null) {
            $this->authorizer->authorize(CreateCurrencyHandler::PERMISSION, PermissionScope::global());
        }

        $code = StoreCode::fromString($command->code);

        // A store named after a top-level path of the application could never be reached.
        if ($this->reservedPaths->isReserved($code->value)) {
            throw new InvalidStoreAttribute('code', "\"{$code->value}\" is reserved for the application");
        }
        $currency = CurrencyCode::fromString($command->newCurrency === null ? $command->currencyCode : $command->newCurrency->code);

        $store = Store::create(
            $this->stores->nextId(),
            $code,
            TranslatedText::of($command->nameAr, $command->nameEn, 'name'),
            CountryCode::fromString($command->countryCode),
            $currency,
            TaxRate::fromBasisPoints($command->taxRateBasisPoints),
            Timezone::fromString($command->timezone),
            $command->position,
        );

        $this->db->transaction(function () use ($command, $store, $code, $currency) {
            // The new currency first, in this same transaction: refused, nothing is made at all.
            if ($command->newCurrency !== null) {
                $this->createCurrency->handle($command->newCurrency);
            }

            if (! $this->currencies->exists($currency)) {
                throw new CurrencyNotFound($currency->value);
            }

            if ($this->stores->codeExists($code)) {
                throw new StoreCodeTaken($code->value);
            }

            // One currency, one store; the unique index on the column refuses a race too.
            if ($this->currencies->isUsedByAnyStore($currency)) {
                throw new CurrencyTaken($currency->value);
            }

            $this->stores->add($store);
            $this->auditLog->record(StoreAudit::created($store));
            $this->directory->invalidate();
            $this->events->dispatch(new StoreCreated((string) Str::uuid(), $store->id()->value, CarbonImmutable::now()));
        });

        return $store->id();
    }
}
