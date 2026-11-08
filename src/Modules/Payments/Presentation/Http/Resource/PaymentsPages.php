<?php

declare(strict_types=1);

namespace Modules\Payments\Presentation\Http\Resource;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\Query\ListPayments\ListPayments;
use Modules\Payments\Application\Query\ListPayments\ListPaymentsHandler;
use Modules\Payments\Application\Query\ListPayments\PaymentRow;
use Modules\Payments\Application\Query\ViewAttempt\AttemptCallRow;
use Modules\Payments\Application\Query\ViewAttempt\AttemptTransactionRow;
use Modules\Payments\Application\Query\ViewAttempt\ViewAttempt;
use Modules\Payments\Application\Query\ViewAttempt\ViewAttemptHandler;
use Modules\Payments\Domain\Model\AttemptState;
use Modules\Platform\Public\Contracts\StoreChoices;
use Modules\Platform\Public\Dto\MoneyData;
use Modules\Platform\Public\Dto\StoreDto;
use Shared\Application\Unauthorized;
use Shared\Domain\Error\DomainError;
use Shared\Domain\ValueObject\Money;

/**
 * The Payments list and an attempt (payments.md §4.2, Q19–Q21), from §9.14's reads. Every amount written by the server in
 * its own currency, every moment in UTC for the page to show in the store's zone.
 */
final readonly class PaymentsPages
{
    /** Money taken that no order keeps: refunded by hand in MyFatoorah's portal (§1.7, rule 8, Q21). */
    private const array REFUND_DUE = [PaymentRow::PAID_NO_ORDER, PaymentRow::PAID_TWICE, PaymentRow::CURRENCY_REFUSED];

    public function __construct(
        private Application $app,
        private StoreChoices $choices,
    ) {}

    /**
     * @throws Unauthorized|DomainError
     */
    public function list(?string $code, ?string $state, ?string $mode, ?string $after, ListPaymentsHandler $list): PaymentsListPage
    {
        $stores = $this->choices->forJobs(PaymentsPermissions::VIEW);
        $chosen = $this->choices->chosen($code, PaymentsPermissions::VIEW);
        $states = array_map(static fn (AttemptState $each): string => $each->value, AttemptState::cases());
        $state = in_array($state, $states, true) ? $state : null;
        $mode = in_array($mode, ['LIVE', 'TEST'], true) ? $mode : null;
        $page = $list->handle(new ListPayments($chosen?->id, $state, $mode, $after));
        $byId = [];

        foreach ($stores as $store) {
            $byId[$store->id] = $store;
        }

        return new PaymentsListPage(
            array_map(fn (StoreDto $each): GatewayStoreData => new GatewayStoreData(strtolower($each->code), $each->name->in($this->locale()), $each->isActive), $stores),
            $chosen === null ? null : strtolower($chosen->code),
            $state,
            $mode,
            $states,
            array_map(fn (PaymentRow $row): PaymentRowData => $this->row($row, $byId[$row->storeId] ?? null), $page->needingAttention),
            $page->needingAttentionCount,
            array_map(fn (PaymentRow $row): PaymentRowData => $this->row($row, $byId[$row->storeId] ?? null), $page->rows),
            $page->nextCursor,
            $chosen->timezone ?? $this->choices->baseTimezone(),
        );
    }

    /**
     * @throws Unauthorized|DomainError
     */
    public function attempt(string $attemptId, ViewAttemptHandler $view): AttemptPage
    {
        $found = $view->handle(new ViewAttempt($attemptId));
        $store = null;

        foreach ($this->choices->forJobs(PaymentsPermissions::VIEW) as $each) {
            $store = $each->id === $found->attempt->storeId ? $each : $store;
        }

        $row = $this->row($found->attempt, $store);

        return new AttemptPage(
            $row,
            $found->attempt->isTest(),
            $found->contact['name'] ?? null,
            $found->contact['email'] ?? null,
            $found->contact['phone'] ?? null,
            $found->contact !== null,
            array_map(static fn (AttemptTransactionRow $each): AttemptTransactionData => new AttemptTransactionData(
                $each->id, $each->gatewayPaymentId, $each->status, $each->succeeded, $each->method, $each->cardBrand,
                $each->amountText === null ? null : trim($each->amountText.' '.($each->currencyCode ?? '')),
                $each->paidAt === null ? null : self::moment($each->paidAt), $each->errorCode, $each->errorMessage,
                $each->mismatch, $each->paidTwice, self::moment($each->seenAt),
            ), $found->transactions),
            array_map(static fn (AttemptCallRow $each): AttemptCallData => new AttemptCallData(
                $each->id, $each->endpoint, $each->httpStatus, $each->tookMs, self::moment($each->at), self::json($each->request), self::json($each->response), $each->error,
            ), $found->calls),
            $found->canAsk,
            array_intersect(self::REFUND_DUE, $row->reasons) !== [],
            $store->timezone ?? $this->choices->baseTimezone(),
        );
    }

    private function row(PaymentRow $row, ?StoreDto $store): PaymentRowData
    {
        $amount = Money::of($row->amountMinor, $row->currencyCode);

        try {
            $money = $store === null ? throw new InvalidArgumentException('No store.') : MoneyData::of($amount, $store, $this->locale());
        } catch (InvalidArgumentException) {
            $money = new MoneyData($amount->format(2).' '.$amount->currencyCode, $amount->minorUnits);
        }

        return new PaymentRowData(
            $row->attemptId, strtolower($store->code ?? ''), $row->orderId, $row->orderNumber, $row->customerName, $money,
            $row->method, $row->state, $row->ownerKind, $row->reasons, self::moment($row->createdAt),
        );
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }

    private static function moment(DateTimeImmutable $at): string
    {
        return $at->setTimezone(new DateTimeZone('UTC'))->format(DateTimeInterface::ATOM);
    }

    /**
     * @param  array<array-key, mixed>|null  $body
     */
    private static function json(?array $body): ?string
    {
        return $body === null ? null : (json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null);
    }
}
