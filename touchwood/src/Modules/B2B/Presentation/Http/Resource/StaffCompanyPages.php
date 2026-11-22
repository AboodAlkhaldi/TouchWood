<?php

declare(strict_types=1);

namespace Modules\B2B\Presentation\Http\Resource;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Foundation\Application;
use Modules\B2B\Application\B2BPermissions;
use Modules\B2B\Application\Query\ListCompanies\CompanySummary;
use Modules\B2B\Application\Query\ListCompanies\ListCompanies;
use Modules\B2B\Application\Query\ListCompanies\ListCompaniesHandler;
use Modules\B2B\Application\Query\StaffCompanyActions\CorrectionChoice;
use Modules\B2B\Application\Query\StaffCompanyActions\StaffCompanyActionsForReader;
use Modules\B2B\Application\Query\ViewCompany\StaffApplicationView;
use Modules\B2B\Application\Query\ViewCompany\ViewCompany;
use Modules\B2B\Application\Query\ViewCompany\ViewCompanyHandler;
use Modules\B2B\Application\Query\ViewMyCompany\AnswerView;
use Modules\B2B\Application\Query\ViewMyCompany\ApplicationValues;
use Modules\B2B\Application\Query\ViewMyCompany\FileView;
use Modules\B2B\Application\Query\ViewMyCompany\FlagView;
use Modules\B2B\Application\Query\ViewMyCompany\RequestView;
use Modules\B2B\Public\Enums\CompanyStatus;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;

/**
 * B2B's staff reads, in the shape the screens want (b2b.md §4.6).
 *
 * It decides nothing. **Who appears and what may be done is B2B's answer** — ListCompanies,
 * ViewCompany and StaffCompanyActionsForReader, each asking for its own job in the right store. What
 * happens here is shaping: a store's id into its name, every time as a moment with its offset in the
 * company's home store's zone (HANDOFF §4) — the page shows it, through Time, in the zone of the
 * store it shows, else the base store's (amendments 23(b), 30) —, and each application's answers
 * beside the requests they answer.
 */
final readonly class StaffCompanyPages
{
    public function __construct(
        private Application $app,
        private PlatformApi $platform,
        private StoreChoices $choices,
        private ListCompaniesHandler $list,
        private ViewCompanyHandler $view,
        private StaffCompanyActionsForReader $actions,
    ) {}

    /**
     * @throws Unauthorized when the filter names a store the reader is not offered - an off one to
     *                      anyone but a Super Admin (platform.md §9.10 #4)
     */
    public function list(?string $search, ?string $status, ?string $storeId, int $page): StaffCompanyListPage
    {
        $offered = $this->choices->forJobs(B2BPermissions::COMPANY_VIEW);

        if ($storeId !== null && ! in_array(strtolower($storeId), array_map(static fn (StoreDto $store): string => $store->id, $offered), true)) {
            throw new Unauthorized(B2BPermissions::COMPANY_VIEW);
        }

        $found = $this->list->handle(new ListCompanies($search, $status, $storeId, $page));
        $stores = $this->stores();

        return new StaffCompanyListPage(
            companies: array_map(fn (CompanySummary $company): StaffCompanyRowData => new StaffCompanyRowData(
                $company->id,
                $company->name,
                $company->status,
                $this->storeName($stores, $company->homeStoreId),
                self::at($company->waitingSince, $stores[$company->homeStoreId] ?? null),
                $company->typeDeactivatedSinceSent,
                self::at($company->statusChangedAt, $stores[$company->homeStoreId] ?? null),
            ), $found->companies),
            total: $found->total,
            page: $found->page,
            perPage: $found->perPage,
            search: $search,
            status: $status === null ? null : strtoupper($status),
            storeId: $storeId === null ? null : strtolower($storeId),
            statuses: array_map(static fn (CompanyStatus $case): string => $case->value, CompanyStatus::cases()),
            // Only the stores the reader may list: filtering by another is refused (amendment 10(j));
            // a Super Admin's off stores too, marked Off (amendment 30, platform.md §9.10).
            stores: array_map(
                fn (StoreDto $store): StaffStoreOptionData => new StaffStoreOptionData($store->id, $store->code, $store->name->in($this->app->getLocale()), $store->isActive),
                $offered,
            ),
            // The list filtered to one store is written in its zone, else the base store's
            // (frontend.md §1.10).
            storeTimezone: $storeId === null ? null : ($stores[strtolower($storeId)] ?? null)?->timezone,
        );
    }

    public function view(string $companyId): StaffCompanyPage
    {
        $company = $this->view->handle(new ViewCompany($companyId));
        $stores = $this->stores();
        $home = $stores[$company->homeStoreId] ?? null;
        $actions = $this->actions->forCompany($company->id);
        $at = static fn (?string $time): ?string => self::at($time, $home);

        $applications = [];
        $sent = $company->applications;

        foreach ($sent as $index => $application) {
            // An application answers the requests of the one sent before it — the next in this
            // newest-first list (§1.2).
            $before = $sent[$index + 1] ?? null;
            $applications[] = $this->application($application, $before, $at, $actions->mayOpenDocuments);
        }

        $holder = $company->holder;

        return new StaffCompanyPage(
            company: new StaffCompanyData(
                $company->id,
                self::values($company->details),
                $company->status,
                $company->statusBeforeSuspension,
                $company->statusReason,
                $at($company->statusChangedAt),
                $company->statusChangedBy,
                $this->storeName($stores, $company->homeStoreId),
                $company->mayOrder,
            ),
            holder: $holder === null ? null : new StaffHolderData(
                trim($holder->firstName.' '.$holder->lastName),
                $holder->email,
                $holder->phone,
                $holder->emailVerified,
                $holder->phoneVerified,
                $holder->anonymized,
                $holder->locale,
            ),
            applications: $applications,
            actions: new StaffCompanyActionsData(
                $actions->mayOpenDocuments,
                $actions->mayApprove,
                $actions->approveRefusal,
                $actions->mayReject,
                $actions->maySuspend,
                $actions->mayReinstate,
                $actions->mayCorrectType,
                $actions->mayChooseOther,
            ),
            typeChoices: array_map(
                static fn (CorrectionChoice $choice): StaffTypeChoiceData => new StaffTypeChoiceData($choice->id, $choice->nameAr, $choice->nameEn, $choice->active),
                $actions->typeChoices,
            ),
            // The company's page is written in its own store's zone (frontend.md §1.10).
            storeTimezone: $home?->timezone,
        );
    }

    /**
     * @param  callable(?string): ?string  $at
     */
    private function application(StaffApplicationView $application, ?StaffApplicationView $before, callable $at, bool $mayOpen): StaffApplicationData
    {
        $labels = [];

        foreach ($before === null ? [] : $before->requests as $request) {
            $labels[$request->id] = $request->label;
        }

        return new StaffApplicationData(
            $application->id,
            $application->reference,
            $application->state,
            self::values($application->values),
            $at($application->submittedAt),
            $at($application->decidedAt),
            $application->decidedBy,
            $application->decisionReason,
            array_map(fn (FileView $file): StaffFileData => new StaffFileData(
                $file->documentTypeId,
                $file->documentTypeNameAr,
                $file->documentTypeNameEn,
                // A file's id and its name go only to whoever may open it: a reader without the
                // private-files permission is never told which file exists (amendment 8(c)), and
                // the name is the company's own.
                $mayOpen ? $file->mediaId : null,
                $mayOpen ? ($this->platform->media($file->mediaId)->originalFilename ?? '') : '',
                (string) $at($file->uploadedAt),
            ), $application->documents),
            array_map(static fn (FlagView $flag): CompanyFlagData => new CompanyFlagData($flag->field, $flag->documentTypeId), $application->flags),
            array_map(static fn (RequestView $request): CompanyRequestData => new CompanyRequestData($request->id, $request->kind, $request->label), $application->requests),
            array_map(fn (AnswerView $answer): StaffAnswerData => new StaffAnswerData(
                $answer->requestId,
                $labels[$answer->requestId] ?? null,
                $answer->text,
                $answer->mediaId !== null,
                $mayOpen ? $answer->mediaId : null,
                $answer->mediaId !== null && $mayOpen ? ($this->platform->media($answer->mediaId)->originalFilename ?? null) : null,
            ), $application->answers),
            $application->typeDeactivatedSinceSent,
        );
    }

    private static function values(ApplicationValues $values): CompanyValuesData
    {
        return new CompanyValuesData(
            $values->name, $values->companyTypeId, $values->companyTypeNameAr, $values->companyTypeNameEn,
            $values->companyTypeOther, $values->crNumber, $values->taxNumber, $values->address, $values->addressId, $values->note,
        );
    }

    /**
     * A time in its store's clock (HANDOFF §4), to the second, with its offset.
     */
    private static function at(?string $time, ?StoreDto $store): ?string
    {
        return $time === null ? null : CarbonImmutable::parse($time)->setTimezone($store === null ? 'UTC' : $store->timezone)->format(DateTimeInterface::ATOM);
    }

    /**
     * @param  array<string, StoreDto>  $stores
     */
    private function storeName(array $stores, string $storeId): string
    {
        $store = $stores[$storeId] ?? null;

        return $store === null ? $storeId : $store->name->in($this->app->getLocale());
    }

    /**
     * Every store, by id, in the stores' own order - the off ones too, so a Super Admin's list
     * filtered to one still names it and writes its times in its zone (amendment 30).
     *
     * @return array<string, StoreDto>
     */
    private function stores(): array
    {
        $stores = [];

        foreach ($this->platform->allStores() as $store) {
            $stores[$store->id] = $store;
        }

        return $stores;
    }
}
