<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Settings;

use LogicException;
use Modules\B2B\Domain\Exception\InvalidCompanyAttribute;
use Modules\B2B\Domain\ValueObject\CompanyName;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RegistrationNumber;
use Modules\B2B\Domain\ValueObject\Remark;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\SettingDefinitionDto;
use Modules\Platform\Public\Enums\SettingScope;
use Modules\Platform\Public\Enums\SettingType;
use Modules\Platform\Public\PlatformPermissions;

/**
 * The company form's minimums (b2b.md §4.5, amendment 16(b)): **settings, one set for every
 * store**, changed under Platform's `platform.settings.update`, each at least 1 and at most its
 * field's maximum. A value is held to them when it is saved and again when the application is sent,
 * so a value saved before a minimum was raised cannot be sent.
 *
 * The maximums and the characters a field takes stay the domain's own (§1.1); only the minimums
 * move, and a database CHECK cannot read a setting, so they are held here in code alone.
 *
 * `forPage()` hands the company page every field's rules — the same numbers this class checks — so
 * the page and the server can never disagree about what is valid (amendment 16(a)).
 */
final readonly class FormRules
{
    public const string NAME_MIN = 'b2b.form.name_min';

    public const string CR_NUMBER_MIN = 'b2b.form.cr_number_min';

    public const string TAX_NUMBER_MIN = 'b2b.form.tax_number_min';

    public const string TYPE_WORDS_MIN = 'b2b.form.company_type_other_min';

    public const string ANSWER_MIN = 'b2b.form.answer_min';

    /**
     * Each field the form holds to a minimum: its setting, its starting value (the owner's,
     * 2026-09-30), its maximum, and whether it is one line.
     *
     * @var array<string, array{setting: string, default: int, max: int, oneLine: bool}>
     */
    private const array FIELDS = [
        'name' => ['setting' => self::NAME_MIN, 'default' => 2, 'max' => CompanyName::MAX, 'oneLine' => true],
        'cr_number' => ['setting' => self::CR_NUMBER_MIN, 'default' => 5, 'max' => RegistrationNumber::MAX, 'oneLine' => true],
        'tax_number' => ['setting' => self::TAX_NUMBER_MIN, 'default' => 5, 'max' => RegistrationNumber::MAX, 'oneLine' => true],
        'company_type_other' => ['setting' => self::TYPE_WORDS_MIN, 'default' => 3, 'max' => CompanyTypeChoice::OTHER_MAX, 'oneLine' => true],
        'answer' => ['setting' => self::ANSWER_MIN, 'default' => 2, 'max' => Remark::MAX, 'oneLine' => false],
    ];

    /** What a CR or tax number may hold besides its length — the domain's own rule, for the page. */
    public const string NUMBER_CHARACTERS = '^[\p{L}\p{N} \-]+$';

    public function __construct(
        private PlatformApi $platform,
    ) {}

    /**
     * @return list<SettingDefinitionDto>
     */
    public static function definitions(): array
    {
        return array_values(array_map(
            static fn (array $field): SettingDefinitionDto => new SettingDefinitionDto(
                $field['setting'], SettingScope::Global, SettingType::Integer, ['min:1', 'max:'.$field['max']], $field['default'], PlatformPermissions::SETTINGS_UPDATE,
            ),
            self::FIELDS,
        ));
    }

    public function minimum(string $field): int
    {
        $rule = self::FIELDS[$field] ?? throw new LogicException("The company form holds no minimum for \"{$field}\".");

        return $this->platform->setting($rule['setting'])->int();
    }

    /**
     * Refuses a value shorter than today's minimum for its field, on that field. A value left empty
     * is not this class's business: whether it may be empty is the form's.
     *
     * @throws InvalidCompanyAttribute
     */
    public function hold(string $field, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        $minimum = $this->minimum($field);

        if (mb_strlen(trim($value)) < $minimum) {
            throw new InvalidCompanyAttribute($field, "at least {$minimum} characters");
        }
    }

    /**
     * Every field's rules as the page checks them before sending anything.
     *
     * @return array<string, array{min: int, max: int, oneLine: bool, characters: string|null}>
     */
    public function forPage(): array
    {
        $rules = [];

        foreach (self::FIELDS as $field => $rule) {
            $rules[$field] = [
                'min' => $this->minimum($field),
                'max' => $rule['max'],
                'oneLine' => $rule['oneLine'],
                'characters' => in_array($field, ['cr_number', 'tax_number'], true) ? self::NUMBER_CHARACTERS : null,
            ];
        }

        // The note has no minimum (amendment 16(b)), only the domain's maximum.
        $rules['note'] = ['min' => 0, 'max' => Remark::MAX, 'oneLine' => false, 'characters' => null];

        return $rules;
    }
}
