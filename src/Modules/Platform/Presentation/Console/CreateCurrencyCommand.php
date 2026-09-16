<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrency;
use Modules\Platform\Application\Command\CreateCurrency\CreateCurrencyHandler;
use Shared\Domain\Error\DomainError;

final class CreateCurrencyCommand extends Command
{
    protected $signature = 'platform:currency:create
        {code : ISO 4217 code}
        {exponent : Decimal places}
        {--name-ar= : Arabic name}
        {--name-en= : English name}
        {--abbreviation-ar= : Arabic letters shown when there is no sign}
        {--abbreviation-en= : English letters shown when there is no sign}
        {--sign= : The official sign, one character (optional)}';

    protected $description = 'Create a currency';

    public function handle(CreateCurrencyHandler $handler): int
    {
        $exponent = $this->argument('exponent');

        if (preg_match('/^\d+\z/', $exponent) !== 1) {
            $this->error('The exponent must be a whole number.');

            return self::FAILURE;
        }

        try {
            $handler->handle(new CreateCurrency(
                $this->argument('code'),
                (int) $exponent,
                $this->text('name-ar'),
                $this->text('name-en'),
                $this->text('abbreviation-ar'),
                $this->text('abbreviation-en'),
                $this->option('sign') === null ? null : $this->text('sign'),
            ));
        } catch (DomainError $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info("Currency {$this->argument('code')} created.");

        return self::SUCCESS;
    }

    private function text(string $option): string
    {
        $value = $this->option($option);

        return is_string($value) ? $value : '';
    }
}
