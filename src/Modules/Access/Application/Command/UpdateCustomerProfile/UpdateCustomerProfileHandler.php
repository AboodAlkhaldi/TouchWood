<?php

declare(strict_types=1);

namespace Modules\Access\Application\Command\UpdateCustomerProfile;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Modules\Access\Application\Audit\CustomerAudit;
use Modules\Access\Application\Customer\CurrentCustomer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Domain\Exception\CustomerNotFound;
use Modules\Access\Domain\Exception\InvalidAccessAttribute;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\ValueObject\Language;
use Modules\Access\Public\Events\CustomerLocaleChanged;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * The customer's own name and communication language. The id comes from who is signed in, never
 * from the request (spec §3.1).
 */
final readonly class UpdateCustomerProfileHandler
{
    public const string PERMISSION = AccessPermissions::ACCOUNT_UPDATE;

    private const int NAME_MAX = 100;

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCustomer $current,
        private CustomerRepository $customers,
        private PlatformApi $platform,
        private Dispatcher $events,
        private Connection $db,
    ) {}

    /**
     * @throws InvalidAccessAttribute|CustomerNotFound
     */
    public function handle(UpdateCustomerProfile $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $customerId = $this->current->id(self::PERMISSION);
        $language = Language::of($command->locale);
        $firstName = $this->name('first_name', $command->firstName);
        $lastName = $this->name('last_name', $command->lastName);

        $this->db->transaction(function () use ($customerId, $firstName, $lastName, $language): void {
            $customer = $this->customers->byId($customerId) ?? throw new CustomerNotFound($customerId);

            $before = clone $customer;
            $customer->updateName($firstName, $lastName);
            $customer->changeLanguage($language);
            $changed = $customer->pullChanges();

            if ($changed === []) {
                return;
            }

            $this->customers->update($customer);
            $this->platform->recordAudit(CustomerAudit::updated('access.customer.profile_updated', $before, $customer, $changed));

            // Ops moves them to that language's newsletter list (amendment 79).
            if (in_array('locale', $changed, true)) {
                $this->events->dispatch(new CustomerLocaleChanged((string) Str::uuid(), $customer->id(), CarbonImmutable::now()));
            }
        }, 3);
    }

    /**
     * @throws InvalidAccessAttribute
     */
    private function name(string $attribute, string $value): string
    {
        $name = trim($value);

        return match (true) {
            $name === '' => throw new InvalidAccessAttribute($attribute, 'required'),
            mb_strlen($name) > self::NAME_MAX => throw new InvalidAccessAttribute($attribute, 'at most '.self::NAME_MAX.' characters'),
            default => $name,
        };
    }
}
