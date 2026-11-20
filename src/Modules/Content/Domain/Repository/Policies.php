<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Repository;

use Modules\Content\Domain\Model\Policy;
use Modules\Content\Domain\Model\PolicyKind;
use Modules\Content\Domain\Model\PolicyVersion;

/**
 * Policies (flags-2026-11.md S3.1, S3.2): each with its translations, its body images, its stores, and the texts it
 * published (its versions), written and read whole.
 */
interface Policies
{
    public function find(string $id): ?Policy;

    /** The policy's row locked for the change (`FOR UPDATE`), then read whole. */
    public function lock(string $id): ?Policy;

    public function add(Policy $policy, ?string $by): void;

    /** Writes the policy whole: its row, its translations, images and stores replaced. */
    public function update(Policy $policy): void;

    /** Writes the policy's own row only - its state, its versions' numbers - leaving its words, images and stores. */
    public function updateRow(Policy $policy): void;

    public function delete(string $id): void;

    /**
     * The published policies of a kind serving any of these stores, other than one.
     *
     * @param  list<string>  $storeIds
     * @return list<Policy>
     */
    public function publishedOfKind(PolicyKind $kind, array $storeIds, string $exceptId): array;

    /** Its last published text, or null before its first publish. */
    public function latestVersion(string $policyId): ?PolicyVersion;

    /** One published text by its number (1, 2, 3...), or null when it has none of that number. */
    public function version(string $policyId, int $number): ?PolicyVersion;

    /** Keeps a published text, with its images by key; a version is never changed after. */
    public function addVersion(PolicyVersion $version): void;
}
