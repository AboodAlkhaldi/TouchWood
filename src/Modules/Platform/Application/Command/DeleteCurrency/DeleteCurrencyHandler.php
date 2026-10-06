<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Command\DeleteCurrency;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Audit\CurrencyAudit;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Exception\CurrencyInUse;
use Modules\Platform\Domain\Exception\CurrencyNotFound;
use Modules\Platform\Domain\Repository\CurrencyRepository;
use Modules\Platform\Domain\ValueObject\CurrencyCode;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * A currency added and never used goes again (platform.md §9.7, the owner's answer of 2026-10-04):
 * by a Super Admin, as adding it is, and only while no store - on or off - charges in it, since a
 * store's currency never changes.
 *
 * The currency's row is locked before its stores are counted: a store opened with it at the same
 * moment takes a key-share lock on that row through its foreign key, so one of the two waits for
 * the other and the count is never stale. The foreign key's RESTRICT stays behind it.
 */
final readonly class DeleteCurrencyHandler
{
    public const string PERMISSION = PlatformPermissions::CURRENCY_DELETE;

    public function __construct(
        private Authorizer $authorizer,
        private CurrencyRepository $currencies,
        private ConnectionInterface $db,
        private StoreDirectory $directory,
        private AuditLog $auditLog,
    ) {}

    /**
     * @throws Unauthorized
     * @throws CurrencyNotFound
     * @throws CurrencyInUse
     */
    public function handle(DeleteCurrency $command): void
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $code = CurrencyCode::fromString($command->code);

        $this->db->transaction(function () use ($code): void {
            $currency = $this->currencies->byCode($code) ?? throw new CurrencyNotFound($code->value);

            if ($this->currencies->isUsedByAnyStore($code)) {
                throw new CurrencyInUse($code->value);
            }

            $this->currencies->delete($code);
            $this->auditLog->record(CurrencyAudit::deleted($currency));
            $this->directory->invalidate();
        });
    }
}
