<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\CreateCurrency;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\CurrencyAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Exception\CurrencyAlreadyExists;
use Modules\Platform\Domain\Model\Currency;
use Modules\Platform\Domain\Repository\CurrencyRepository;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Domain\ValueObject\TranslatedText;
use Shared\Application\Authorizer;

final readonly class CreateCurrencyHandler
{
    public const string PERMISSION = 'platform.currency.create';

    public function __construct(
        private Authorizer $authorizer,
        private CurrencyRepository $currencies,
        private ConnectionInterface $db,
        private StoreDirectory $directory,
        private AuditLog $auditLog,
    ) {}

    public function handle(CreateCurrency $command): void
    {
        $this->authorizer->authorize(self::PERMISSION);

        $currency = Currency::create(
            CurrencyCode::fromString($command->code),
            $command->exponent,
            TranslatedText::of($command->nameAr, $command->nameEn, 'name'),
            TranslatedText::of($command->abbreviationAr, $command->abbreviationEn, 'abbreviation'),
            $command->sign,
        );

        $this->db->transaction(function () use ($currency) {
            if ($this->currencies->exists($currency->code())) {
                throw new CurrencyAlreadyExists($currency->code()->value);
            }

            $this->currencies->add($currency);
            $this->auditLog->record(CurrencyAudit::created($currency));
            $this->directory->invalidate();
        });
    }
}
