<?php

namespace Modules\Platform\Application\Command\UpdateStore;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Exception\StoreAttributeImmutable;
use Modules\Platform\Domain\Exception\StoreNotFound;
use Modules\Platform\Domain\Model\Store;
use Modules\Platform\Domain\Repository\StoreRepository;
use Modules\Platform\Domain\ValueObject\StoreCode;
use Modules\Platform\Domain\ValueObject\TaxRate;
use Modules\Platform\Domain\ValueObject\Timezone;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Modules\Platform\Public\Events\StoreUpdated;
use Shared\Application\Authorizer;

final readonly class UpdateStoreHandler
{
    public const string PERMISSION = 'platform.store.update';

    public function __construct(
        private Authorizer $authorizer,
        private StoreRepository $stores,
        private ConnectionInterface $db,
        private Dispatcher $events,
        private StoreDirectory $directory,
    ) {}

    public function handle(UpdateStore $command): void
    {
        $code = StoreCode::fromString($command->storeCode);

        $this->db->transaction(function () use ($command, $code) {
            $store = $this->stores->byCode($code) ?? throw new StoreNotFound($code->value);

            // Checked against this store: an admin of one store cannot edit another.
            $this->authorizer->authorize(self::PERMISSION, $store->id());
            $this->refuseImmutableChanges($store, $command);

            if ($command->nameAr !== null || $command->nameEn !== null) {
                $store->rename(TranslatedText::of(
                    $command->nameAr ?? $store->name()->ar,
                    $command->nameEn ?? $store->name()->en,
                    'name',
                ));
            }

            if ($command->taxRateBasisPoints !== null) {
                $store->changeTaxRate(TaxRate::fromBasisPoints($command->taxRateBasisPoints));
            }

            if ($command->timezone !== null) {
                $store->changeTimezone(Timezone::fromString($command->timezone));
            }

            if ($command->position !== null) {
                $store->reposition($command->position);
            }

            $changed = $store->pullChanges();

            if ($changed === []) {
                return;
            }

            $this->stores->update($store);
            $this->events->dispatch(new StoreUpdated((string) Str::uuid(), $store->id()->value, $changed, CarbonImmutable::now()));
        });

        // After the commit, so no other request can re-cache the old data in between.
        $this->directory->forget();
    }

    private function refuseImmutableChanges(Store $store, UpdateStore $command): void
    {
        $attempts = [
            'code' => [$command->newCode, $store->code()->value],
            'country' => [$command->countryCode, $store->country()->value],
            'currency' => [$command->currencyCode, $store->currency()->value],
        ];

        foreach ($attempts as $attribute => [$requested, $current]) {
            if ($requested !== null && $requested !== $current) {
                throw new StoreAttributeImmutable($attribute);
            }
        }
    }
}
