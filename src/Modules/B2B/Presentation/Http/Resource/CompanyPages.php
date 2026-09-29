<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\B2B\Application\Query\ViewMyCompany\AnswerView;
use Modules\B2B\Application\Query\ViewMyCompany\ApplicationValues;
use Modules\B2B\Application\Query\ViewMyCompany\FileView;
use Modules\B2B\Application\Query\ViewMyCompany\FlagView;
use Modules\B2B\Application\Query\ViewMyCompany\MyCompanyView;
use Modules\B2B\Application\Query\ViewMyCompany\RequestView;
use Modules\B2B\Application\Query\ViewMyCompany\SentApplicationView;
use Modules\B2B\Application\Query\ViewMyCompany\TypeOption;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * The company page's data (b2b.md §4.5), from what ViewMyCompany answers.
 *
 * **Every time is written in the home store's time zone** (HANDOFF §4; owner, 2026-09-29: "each
 * store will have its own time"): UTC underneath, the store's clock on the screen. The page shows
 * the time as it is given and never converts it again.
 */
final readonly class CompanyPages
{
    /** Platform's setting for the largest private file (platform.md §1.4), read through its contract. */
    private const string MAX_PRIVATE_BYTES = 'platform.media.max_private_bytes';

    public function __construct(
        private PlatformApi $platform,
    ) {}

    public function page(MyCompanyView $view): CompanyPage
    {
        $zone = $this->platform->store(StoreId::fromString($view->homeStoreId))->timezone ?? 'UTC';
        $time = static fn (?string $at): ?string => $at === null ? null : CarbonImmutable::parse($at)->setTimezone($zone)->format(DateTimeInterface::ATOM);
        $files = static fn (array $files): array => array_values(array_map(
            static fn (FileView $file): CompanyFileData => new CompanyFileData(
                $file->documentTypeId, $file->documentTypeNameAr, $file->documentTypeNameEn, $file->mediaId,
                (string) $time($file->uploadedAt), $file->noLongerAccepted,
            ),
            $files,
        ));

        $company = $view->company;
        $draft = $view->draft;

        return new CompanyPage(
            $view->stage?->value,
            $company === null ? null : new CompanyStatusData(
                $company->id, self::values($company->details), $company->status, $company->statusReason,
                $time($company->statusChangedAt), $company->mayOrder,
            ),
            $draft === null ? null : new CompanyDraftData(
                $draft->id, self::values($draft->values), $draft->typeNoLongerAccepted, $files($draft->documents),
                self::flags($draft->flags), self::requests($draft->requests), self::answers($draft->answers),
            ),
            self::options($view->companyTypes),
            self::options($view->documentTypes),
            array_map(
                static fn (SentApplicationView $sent): CompanyApplicationData => new CompanyApplicationData(
                    $sent->id, $sent->reference, $sent->state, self::values($sent->values), $time($sent->submittedAt),
                    $time($sent->decidedAt), $sent->decisionReason, $files($sent->documents),
                    self::flags($sent->flags), self::requests($sent->requests), self::answers($sent->answers),
                ),
                $view->history,
            ),
            $view->bankAccount === null ? null : new CompanyBankAccountData($view->bankAccount->iban, $view->bankAccount->bank, $view->bankAccount->holder),
            $this->platform->setting(self::MAX_PRIVATE_BYTES)->int(),
        );
    }

    private static function values(ApplicationValues $values): CompanyValuesData
    {
        return new CompanyValuesData(
            $values->name, $values->companyTypeId, $values->companyTypeNameAr, $values->companyTypeNameEn,
            $values->companyTypeOther, $values->crNumber, $values->taxNumber, $values->address, $values->note,
        );
    }

    /**
     * @param  list<TypeOption>  $options
     * @return list<CompanyTypeOptionData>
     */
    private static function options(array $options): array
    {
        return array_map(
            static fn (TypeOption $option): CompanyTypeOptionData => new CompanyTypeOptionData($option->id, $option->nameAr, $option->nameEn, $option->greyed, $option->required),
            $options,
        );
    }

    /**
     * @param  list<FlagView>  $flags
     * @return list<CompanyFlagData>
     */
    private static function flags(array $flags): array
    {
        return array_map(static fn (FlagView $flag): CompanyFlagData => new CompanyFlagData($flag->field, $flag->documentTypeId), $flags);
    }

    /**
     * @param  list<RequestView>  $requests
     * @return list<CompanyRequestData>
     */
    private static function requests(array $requests): array
    {
        return array_map(static fn (RequestView $request): CompanyRequestData => new CompanyRequestData($request->id, $request->kind, $request->label), $requests);
    }

    /**
     * @param  list<AnswerView>  $answers
     * @return list<CompanyAnswerData>
     */
    private static function answers(array $answers): array
    {
        return array_map(static fn (AnswerView $answer): CompanyAnswerData => new CompanyAnswerData($answer->requestId, $answer->text, $answer->mediaId), $answers);
    }
}
