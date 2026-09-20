<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Modules\Access\Application\Query\CustomerReader;
use stdClass;

final readonly class DatabaseCustomerReader implements CustomerReader
{
    private const string TABLE = 'access.customers';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function customers(?array $homeStoreIds, ?string $search, ?string $status, int $page, int $perPage): array
    {
        $query = $this->query($homeStoreIds, $search, $status);
        $total = (clone $query)->count();

        $rows = $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage(max($page, 1), $perPage)
            ->get();

        return ['total' => $total, 'rows' => array_values(array_map($this->toRow(...), $rows->all()))];
    }

    public function customer(string $customerId): ?array
    {
        if (! Ulids::valid($customerId)) {
            return null;
        }

        $row = $this->db->table(self::TABLE)->where('id', strtolower($customerId))->first();

        return $row instanceof stdClass ? $this->toRow($row) : null;
    }

    /**
     * @param  list<string>|null  $homeStoreIds
     */
    private function query(?array $homeStoreIds, ?string $search, ?string $status): Builder
    {
        $query = $this->db->table(self::TABLE);

        if ($homeStoreIds !== null) {
            $query->whereIn('home_store_id', array_map(strtolower(...), $homeStoreIds));
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($search))).'%';

            $query->where(function (Builder $where) use ($like): void {
                $where->whereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(first_name) like ?', [$like])
                    ->orWhereRaw('lower(last_name) like ?', [$like])
                    ->orWhere('phone', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(stdClass $row): array
    {
        return [
            'id' => (string) $row->id,
            'first_name' => (string) $row->first_name,
            'last_name' => (string) $row->last_name,
            'email' => (string) $row->email,
            'phone' => $row->phone === null ? null : (string) $row->phone,
            'status' => (string) $row->status,
            'account_type' => (string) $row->account_type,
            'locale' => (string) $row->locale,
            'home_store_id' => (string) $row->home_store_id,
            'last_store_id' => (string) $row->last_store_id,
            'email_verified' => $row->email_verified_at !== null,
            'phone_verified' => $row->phone_verified_at !== null,
            'deletion_scheduled_for' => $row->deletion_scheduled_for === null ? null : (string) $row->deletion_scheduled_for,
            'anonymized' => $row->anonymized_at !== null,
            'registered_at' => (string) $row->created_at,
        ];
    }
}
