<?php

declare(strict_types=1);

namespace Modules\B2B\Application\Query;

use DateTimeInterface;
use LogicException;
use Modules\B2B\Application\Query\ViewMyCompany\AnswerView;
use Modules\B2B\Application\Query\ViewMyCompany\ApplicationValues;
use Modules\B2B\Application\Query\ViewMyCompany\FileView;
use Modules\B2B\Application\Query\ViewMyCompany\FlagView;
use Modules\B2B\Application\Query\ViewMyCompany\RequestView;
use Modules\B2B\Domain\Model\Application;
use Modules\B2B\Domain\Model\CompanyType;
use Modules\B2B\Domain\Model\DocumentType;
use Modules\B2B\Domain\ValueObject\ApplicationFlag;
use Modules\B2B\Domain\ValueObject\ApplicationRequest;
use Modules\B2B\Domain\ValueObject\AttachedDocument;
use Modules\B2B\Domain\ValueObject\CompanyAddress;
use Modules\B2B\Domain\ValueObject\CompanyTypeChoice;
use Modules\B2B\Domain\ValueObject\RequestAnswer;

/**
 * How an application and its parts are shown — the same to the company (ViewMyCompany) and to staff
 * (ViewCompany), so the two never drift apart. Types are named from the home store's lists.
 */
final class ApplicationViews
{
    /**
     * @param  array<string, CompanyType>  $companyTypes
     */
    public static function applicationValues(Application $application, array $companyTypes): ApplicationValues
    {
        return self::values(
            $application->name()?->value, $application->type(), $application->crNumber()?->value,
            $application->taxNumber()?->value, $application->address(), $application->note()?->value, $companyTypes,
        );
    }

    /**
     * @param  array<string, CompanyType>  $companyTypes
     */
    public static function values(?string $name, ?CompanyTypeChoice $type, ?string $crNumber, ?string $taxNumber, ?CompanyAddress $address, ?string $note, array $companyTypes): ApplicationValues
    {
        $listed = $type?->typeId === null ? null : ($companyTypes[$type->typeId] ?? null);

        return new ApplicationValues(
            $name,
            $type?->typeId,
            $listed?->name()->ar,
            $listed?->name()->en,
            $type?->other,
            $crNumber,
            $taxNumber,
            $address?->value,
            $address?->addressId,
            $note,
        );
    }

    /**
     * @param  array<string, AttachedDocument>  $documents
     * @param  array<string, DocumentType>  $documentTypes
     * @return list<FileView>
     */
    public static function files(array $documents, array $documentTypes, bool $markInactive): array
    {
        $files = [];

        foreach ($documents as $typeId => $document) {
            $type = $documentTypes[$typeId] ?? null;

            $files[] = new FileView(
                $typeId,
                $type?->name()->ar,
                $type?->name()->en,
                $document->mediaId,
                (string) self::time($document->uploadedAt),
                // A draft never sends anything deactivated (§1.3); what was sent is history.
                $markInactive && ($type === null || ! $type->isActive()),
            );
        }

        return $files;
    }

    /**
     * @param  list<ApplicationFlag>  $flags
     * @return list<FlagView>
     */
    public static function flags(array $flags): array
    {
        return array_map(static fn (ApplicationFlag $flag): FlagView => new FlagView($flag->field?->value, $flag->documentTypeId), $flags);
    }

    /**
     * @param  list<ApplicationRequest>  $requests
     * @return list<RequestView>
     */
    public static function requests(array $requests): array
    {
        return array_map(static fn (ApplicationRequest $request): RequestView => new RequestView($request->id, $request->kind->value, $request->label), $requests);
    }

    /**
     * @param  array<string, RequestAnswer>  $answers
     * @return list<AnswerView>
     */
    public static function answers(array $answers): array
    {
        return array_values(array_map(static fn (RequestAnswer $answer): AnswerView => new AnswerView($answer->requestId, $answer->text?->value, $answer->mediaId), $answers));
    }

    public static function time(?DateTimeInterface $at): ?string
    {
        return $at?->format(DATE_ATOM);
    }

    /**
     * A sent application's number (amendment 14(g)). Every sent one has one — Application's own rule
     * and the table's CHECK — so a missing one is a bug, not a blank to show.
     */
    public static function reference(Application $sent): string
    {
        return $sent->reference()->value ?? throw new LogicException("The sent application {$sent->id()} has no reference.");
    }
}
