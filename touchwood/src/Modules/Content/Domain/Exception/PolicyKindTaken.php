<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Exception;

use Shared\Domain\Error\ErrorCategory;

/**
 * A second published policy of one kind in a store (flags-2026-11.md S3.1: "A store has at most one published policy
 * of each kind (Other excepted)"), naming the store, the kind and the other policy's title.
 */
final class PolicyKindTaken extends ContentError
{
    /**
     * @param  string  $store  the store's name
     * @param  string  $kind  the kind's name
     * @param  string  $title  the published policy's title
     */
    public function __construct(
        public readonly string $store,
        public readonly string $kind,
        public readonly string $title,
    ) {
        parent::__construct("{$store} already has a published {$kind}: {$title}.");
    }

    public function type(): string
    {
        return 'content.policy_kind_taken';
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }

    public function context(): array
    {
        return ['store' => $this->store, 'kind' => $this->kind, 'title' => $this->title];
    }
}
