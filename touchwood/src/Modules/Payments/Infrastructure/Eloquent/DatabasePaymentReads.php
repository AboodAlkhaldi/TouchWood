<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Payments\Application\Query\ListPayments\PaymentFilters;
use Modules\Payments\Application\Query\ListPayments\PaymentRow;
use Modules\Payments\Application\Query\PaymentReads;
use Modules\Payments\Application\Query\ViewAttempt\AttemptCallRow;
use Modules\Payments\Application\Query\ViewAttempt\AttemptTransactionRow;
use stdClass;

final readonly class DatabasePaymentReads implements PaymentReads
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function rows(PaymentFilters $filters, ?string $after, int $most): array
    {
        return self::rowsOf($this->selected($this->filtered($filters))->where('a.needs_attention', false)
            ->when($after !== null, static fn (Builder $query) => $query->where('a.id', '<', $after))
            ->orderByDesc('a.id')->limit($most)->get()->all());
    }

    public function needingAttention(PaymentFilters $filters, int $most): array
    {
        return self::rowsOf($this->selected($this->filtered($filters))->where('a.needs_attention', true)->orderByDesc('a.id')->limit($most)->get()->all());
    }

    public function needingAttentionCount(PaymentFilters $filters): int
    {
        return $this->filtered($filters)->where('a.needs_attention', true)->count();
    }

    public function row(string $attemptId): ?PaymentRow
    {
        $row = $this->selected($this->db->table('payments.attempts as a'))->where('a.id', $attemptId)->first();

        return $row instanceof stdClass ? self::rowOf($row) : null;
    }

    public function contact(string $attemptId): ?array
    {
        $row = $this->db->table('payments.attempts')->where('id', $attemptId)->first(['customer_name', 'customer_email', 'customer_phone']);

        if (! $row instanceof stdClass) {
            return null;
        }

        return [
            'name' => $row->customer_name === null ? null : (string) $row->customer_name,
            'email' => $row->customer_email === null ? null : (string) $row->customer_email,
            'phone' => $row->customer_phone === null ? null : (string) $row->customer_phone,
        ];
    }

    public function transactions(string $attemptId): array
    {
        return array_values(array_map(static fn (stdClass $row): AttemptTransactionRow => new AttemptTransactionRow(
            (string) $row->id,
            (string) $row->gateway_payment_id,
            (string) $row->status,
            (bool) $row->succeeded,
            (string) $row->method,
            $row->card_brand === null ? null : (string) $row->card_brand,
            $row->amount_minor === null ? null : (int) $row->amount_minor,
            $row->amount_text === null ? null : (string) $row->amount_text,
            $row->currency_code === null ? null : (string) $row->currency_code,
            self::moment($row->paid_at),
            $row->error_code === null ? null : (string) $row->error_code,
            $row->error_message === null ? null : (string) $row->error_message,
            (bool) $row->mismatch,
            (bool) $row->paid_twice,
            self::moment($row->seen_at) ?? new CarbonImmutable,
        ), $this->db->table('payments.transactions')->where('attempt_id', $attemptId)->orderBy('id')->get()->all()));
    }

    public function calls(string $attemptId): array
    {
        return array_values(array_map(static fn (stdClass $row): AttemptCallRow => new AttemptCallRow(
            (string) $row->id,
            (string) $row->endpoint,
            self::json($row->request),
            $row->http_status === null ? null : (int) $row->http_status,
            self::json($row->response),
            $row->error === null ? null : (string) $row->error,
            (int) $row->took_ms,
            self::moment($row->at) ?? new CarbonImmutable,
        ), $this->db->table('payments.calls')->where('attempt_id', $attemptId)->orderBy('id')->get()->all()));
    }

    private function filtered(PaymentFilters $filters): Builder
    {
        return $this->db->table('payments.attempts as a')
            ->when($filters->stores !== null, static fn (Builder $query) => $query->whereIn('a.store_id', $filters->stores ?? []))
            ->when($filters->state !== null, static fn (Builder $query) => $query->where('a.state', $filters->state))
            ->when($filters->mode !== null, static fn (Builder $query) => $query->where('a.mode', $filters->mode));
    }

    private function selected(Builder $query): Builder
    {
        return $query
            ->select(['a.id', 'a.store_id', 'a.owner_id', 'a.owner_kind', 'a.order_id', 'a.customer_name', 'a.amount_minor', 'a.currency_code', 'a.state', 'a.mode', 'a.needs_attention', 'a.mismatch', 'a.paid_twice', 'a.currency_refused', 'a.nothing_charged', 'a.created_at'])
            // As MyFatoorah's answer named it: a success's, or the last that came.
            ->selectRaw('(SELECT t.method FROM payments.transactions t WHERE t.attempt_id = a.id ORDER BY t.succeeded DESC, t.id DESC LIMIT 1) AS method');
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<PaymentRow>
     */
    private static function rowsOf(array $rows): array
    {
        $found = [];

        foreach ($rows as $row) {
            if ($row instanceof stdClass) {
                $found[] = self::rowOf($row);
            }
        }

        return $found;
    }

    private static function rowOf(stdClass $row): PaymentRow
    {
        return new PaymentRow(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->owner_id,
            (string) $row->owner_kind,
            (string) $row->order_id,
            null,
            $row->customer_name === null ? null : (string) $row->customer_name,
            (int) $row->amount_minor,
            (string) $row->currency_code,
            $row->method === null ? null : (string) $row->method,
            (string) $row->state,
            (string) $row->mode,
            (bool) $row->needs_attention,
            (bool) $row->mismatch,
            (bool) $row->paid_twice,
            (bool) $row->currency_refused,
            (bool) $row->nothing_charged,
            self::moment($row->created_at) ?? new CarbonImmutable,
        );
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function json(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function moment(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : (new CarbonImmutable((string) $value))->toDateTimeImmutable();
    }
}
