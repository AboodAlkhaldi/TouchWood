<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Command\SaveApplicationDraft;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\B2B\Application\Account\CurrentCompanyAccount;
use Modules\B2B\Application\Account\SavedAddresses;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Draft\OpenDrafts;
use Modules\B2B\Application\Settings\FormRules;
use Modules\B2B\Domain\Exception\ApplicationNotEditable;
use Modules\B2B\Domain\Exception\ApplicationNotFound;
use Modules\B2B\Domain\Exception\CompanySuspended;
use Modules\B2B\Domain\Exception\CompanyTypeInactive;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\Exception\NotACompanyAccount;
use Modules\B2B\Domain\Repository\ApplicationRepository;
use Modules\B2B\Domain\Repository\CompanyTypeRepository;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyText;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * Saves the account's open draft (b2b.md §1.2, §3.1).
 *
 * - **Each value is checked when it is saved** (amendment 4): a value that is there must already be
 *   a valid one, refused on its own field. Only completeness waits for sending.
 * - **Only the fields sent change** (amendment 5).
 * - **Minimums**, settings every store shares, are held on each value sent (amendment 16(b)).
 * - **The address is picked** from the account's saved addresses, by its id, and kept as a copy
 *   (amendment 16(f)); another account's, or one its format no longer accepts, is refused.
 * - **A newly chosen listed type** must be one of the home store's (InvalidCompanyAttribute,
 *   amendment 6(d)) and still offered (CompanyTypeInactive). One the draft already holds is left as
 *   it is when sent again, deactivated or not: it is marked "no longer accepted" until the company
 *   chooses again, and sending is what refuses it (§1.3).
 *
 * No company row is written and nothing is audited (amendment 4): the wizard saves as the person
 * types, and every save is part of an application that is then either sent or discarded.
 */
final readonly class SaveApplicationDraftHandler
{
    public const string PERMISSION = B2BPermissions::APPLY;

    /** Every key a save may carry. */
    public const array FIELDS = ['name', 'company_type_id', 'company_type_other', 'cr_number', 'tax_number', 'address_id', 'note'];

    public function __construct(
        private Authorizer $authorizer,
        private CurrentCompanyAccount $account,
        private OpenDrafts $drafts,
        private ApplicationRepository $applications,
        private CompanyTypeRepository $companyTypes,
        private FormRules $rules,
        private SavedAddresses $addresses,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws NotACompanyAccount|InvalidCompanyAttribute|CompanyTypeInactive
     * @throws ApplicationNotFound|CompanySuspended|ApplicationNotEditable
     */
    public function handle(SaveApplicationDraft $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $account = $this->account->get(self::PERMISSION);

        $store = $this->account->store($account);

        $this->db->transaction(function () use ($account, $command, $store): void {
            // The draft first (amendment 17(h)): a suspended company, or an account with nothing
            // open, is told so before any value is weighed. The draft of the store being browsed
            // (amendment 18).
            $draft = $this->drafts->forChange($account->id, $store)->draft;
            $sent = self::values($command->fields);

            // Today's minimums (amendment 16(b)), on the field that falls short.
            $this->rules->hold('name', array_key_exists('name', $sent) ? $sent['name']?->value : null);
            $this->rules->hold('cr_number', array_key_exists('cr_number', $sent) ? $sent['cr_number']?->value : null);
            $this->rules->hold('tax_number', array_key_exists('tax_number', $sent) ? $sent['tax_number']?->value : null);
            $this->rules->hold('company_type_other', array_key_exists('type', $sent) ? $sent['type']?->other : null);

            // Picked from the account's saved addresses, and kept as a copy (amendment 16(f)).
            if (array_key_exists('address_id', $command->fields)) {
                $picked = $command->fields['address_id'];
                $sent['address'] = $picked === null || trim($picked) === '' ? null : $this->addresses->pick($account->id, $picked);
            }

            $type = array_key_exists('type', $sent) ? $sent['type'] : $draft->type();

            if ($type?->typeId !== null && $type->typeId !== $draft->type()?->typeId) {
                $this->requireChoosable($type->typeId, $draft->storeId());
            }

            $draft->describe(
                array_key_exists('name', $sent) ? $sent['name'] : $draft->name(),
                $type,
                array_key_exists('cr_number', $sent) ? $sent['cr_number'] : $draft->crNumber(),
                array_key_exists('tax_number', $sent) ? $sent['tax_number'] : $draft->taxNumber(),
                array_key_exists('address', $sent) ? $sent['address'] : $draft->address(),
                array_key_exists('note', $sent) ? $sent['note'] : $draft->note(),
            );

            $this->applications->update($draft);
        }, 3);
    }

    /**
     * A listed type the draft did not hold before: the home store's, and offered (§1.3).
     *
     * @throws InvalidCompanyAttribute|CompanyTypeInactive
     */
    private function requireChoosable(string $typeId, string $homeStoreId): void
    {
        foreach ($this->companyTypes->all($homeStoreId) as $companyType) {
            if ($companyType->id() === $typeId) {
                if (! $companyType->isActive()) {
                    throw new CompanyTypeInactive;
                }

                return;
            }
        }

        // A type of another store is as unknown as one that does not exist (amendment 6(d)).
        throw new InvalidCompanyAttribute('company_type', "not one of the home store's types");
    }

    /**
     * Every value sent, checked on its own field; a value sent empty clears it.
     *
     * @param  array<string, string|null>  $fields
     * @return array{name?: CompanyName|null, type?: CompanyTypeChoice|null, cr_number?: RegistrationNumber|null, tax_number?: RegistrationNumber|null, address?: CompanyAddress|null, note?: Remark|null}
     *
     * @throws InvalidCompanyAttribute
     */
    private static function values(array $fields): array
    {
        foreach (array_keys($fields) as $field) {
            if (! in_array($field, self::FIELDS, true)) {
                // The caller builds the command from its own form: an unknown key is its bug.
                throw new LogicException("A draft has no field \"{$field}\".");
            }
        }

        $given = static function (string $field) use ($fields): ?string {
            $value = $fields[$field] ?? null;

            // Empty as the page sees it (amendment 17(a)): only spaces is nothing.
            return $value === null || CompanyText::trimmed($value) === '' ? null : $value;
        };

        $sent = [];

        if (array_key_exists('name', $fields)) {
            $sent['name'] = ($value = $given('name')) === null ? null : CompanyName::of($value);
        }

        if (array_key_exists('company_type_id', $fields) || array_key_exists('company_type_other', $fields)) {
            $listed = $given('company_type_id');
            $other = $given('company_type_other');

            if ($listed !== null && $other !== null) {
                throw new InvalidCompanyAttribute('company_type', 'a listed type or Other, not both');
            }

            $sent['type'] = match (true) {
                $listed !== null => CompanyTypeChoice::listed($listed),
                $other !== null => CompanyTypeChoice::other($other),
                default => null,
            };
        }

        if (array_key_exists('cr_number', $fields)) {
            $sent['cr_number'] = ($value = $given('cr_number')) === null ? null : RegistrationNumber::of('cr_number', $value);
        }

        if (array_key_exists('tax_number', $fields)) {
            $sent['tax_number'] = ($value = $given('tax_number')) === null ? null : RegistrationNumber::of('tax_number', $value);
        }

        if (array_key_exists('note', $fields)) {
            $sent['note'] = ($value = $given('note')) === null ? null : Remark::of('note', $value);
        }

        return $sent;
    }
}
