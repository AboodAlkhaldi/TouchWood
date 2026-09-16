<?php

namespace Modules\Platform\Application\Command\UpdateCurrency;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Platform\Application\Audit\CurrencyAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Exception\CurrencyNotFound;
use Modules\Platform\Domain\Repository\CurrencyRepository;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Modules\Platform\Public\Events\CurrencyUpdated;
use Shared\Application\Authorizer;

final readonly class UpdateCurrencyHandler
{
    public const string PERMISSION = 'platform.currency.update';

    public function __construct(
        private Authorizer $authorizer,
        private CurrencyRepository $currencies,
        private ConnectionInterface $db,
        private Dispatcher $events,
        private StoreDirectory $directory,
        private AuditLog $auditLog,
    ) {}

    public function handle(UpdateCurrency $command): void
    {
        $this->authorizer->authorize(self::PERMISSION);

        $code = CurrencyCode::fromString($command->code);

        $this->db->transaction(function () use ($command, $code) {
            $currency = $this->currencies->byCode($code) ?? throw new CurrencyNotFound($code->value);
            $before = CurrencyAudit::attributes($currency);

            if ($command->exponent !== null) {
                $currency->changeExponent($command->exponent, $this->currencies->isUsedByAnyStore($code));
            }

            if ($command->nameAr !== null || $command->nameEn !== null) {
                $currency->rename(TranslatedText::of(
                    $command->nameAr ?? $currency->name()->ar,
                    $command->nameEn ?? $currency->name()->en,
                    'name',
                ));
            }

            if ($command->abbreviationAr !== null || $command->abbreviationEn !== null) {
                $currency->changeAbbreviation(TranslatedText::of(
                    $command->abbreviationAr ?? $currency->abbreviation()->ar,
                    $command->abbreviationEn ?? $currency->abbreviation()->en,
                    'abbreviation',
                ));
            }

            if ($command->clearSign) {
                $currency->changeSign(null);
            } elseif ($command->sign !== null) {
                $currency->changeSign($command->sign);
            }

            $changed = $currency->pullChanges();

            if ($changed === []) {
                return;
            }

            $this->currencies->update($currency);
            $this->auditLog->record(CurrencyAudit::updated($currency, $before, $changed));
            $this->events->dispatch(new CurrencyUpdated((string) Str::uuid(), $code->value, $changed, CarbonImmutable::now()));
        });

        // After the commit, so no other request can re-cache the old data in between.
        $this->directory->forget();
    }
}
