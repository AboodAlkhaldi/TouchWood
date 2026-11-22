<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use DateTimeImmutable;
use Modules\Content\Domain\Model\Redirect;

/** The redirects (content.md §1.15, §5: `content.redirects`), keyed by the old address. */
interface Redirects
{
    /** The line of an old address, locked when asked. */
    public function find(string $oldPath, bool $lock = false): ?Redirect;

    /**
     * The lines of these old addresses, as they are now, keyed by old address (Apply Sheet, the sheet's page).
     *
     * @param  list<string>  $oldPaths
     * @return array<string, Redirect>
     */
    public function findMany(array $oldPaths, bool $lock = false): array;

    /** Where an old address goes: one read, by the key, for the visitor's answer. */
    public function newPathOf(string $oldPath): ?string;

    public function add(Redirect $redirect): void;

    /** Writes the line, under its old address as it was (`$was`, when the old address itself changed). */
    public function update(Redirect $redirect, ?string $was = null): void;

    public function delete(string $oldPath): void;

    /** One more visit and its moment, in one write. */
    public function countVisit(string $oldPath, DateTimeImmutable $at): void;

    public function resetCount(string $oldPath): void;

    /** Check Addresses' mark of a line, unless its new address changed since it was read. */
    public function mark(string $oldPath, string $newPath, string $result, ?string $finalPath, DateTimeImmutable $at): void;

    /**
     * A page of the list, most visited first (W11), each searched by its old or new address when `q` is given.
     *
     * @return array{rows: list<Redirect>, next: string|null}
     */
    public function page(?string $q, ?string $after, int $limit): array;

    /**
     * A page of every line by old address, for Check Addresses.
     *
     * @return list<Redirect>
     */
    public function after(?string $oldPath, int $limit): array;
}
