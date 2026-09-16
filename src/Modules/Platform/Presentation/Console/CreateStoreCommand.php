<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Console;

use Illuminate\Console\Command;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Shared\Domain\Error\DomainError;

/**
 * Creates a store with every attribute at once. A store with anything missing is refused,
 * so no store ever exists half-configured (Platform spec §1.1).
 */
final class CreateStoreCommand extends Command
{
    protected $signature = 'platform:store:create
        {code : URL segment, 2 to 8 lowercase letters}
        {--name-ar= : Arabic name}
        {--name-en= : English name}
        {--country= : ISO 3166-1 alpha-2 country code}
        {--currency= : ISO 4217 code of an existing currency}
        {--tax-basis-points= : Tax rate in basis points, 1500 = 15%}
        {--timezone= : IANA timezone}
        {--position=0 : Display order}';

    protected $description = 'Create a store, complete, in one step';

    public function handle(CreateStoreHandler $handler): int
    {
        $taxRate = $this->option('tax-basis-points');
        $position = $this->option('position');

        foreach (['tax-basis-points' => $taxRate, 'position' => $position] as $option => $value) {
            if (! is_string($value) || preg_match('/^\d+\z/', $value) !== 1) {
                $this->error("--{$option} is required and must be a whole number.");

                return self::FAILURE;
            }
        }

        try {
            $handler->handle(new CreateStore(
                $this->argument('code'),
                $this->text('name-ar'),
                $this->text('name-en'),
                $this->text('country'),
                $this->text('currency'),
                (int) $taxRate,
                $this->text('timezone'),
                (int) $position,
            ));
        } catch (DomainError $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->info("Store {$this->argument('code')} created.");

        return self::SUCCESS;
    }

    private function text(string $option): string
    {
        $value = $this->option($option);

        return is_string($value) ? $value : '';
    }
}
