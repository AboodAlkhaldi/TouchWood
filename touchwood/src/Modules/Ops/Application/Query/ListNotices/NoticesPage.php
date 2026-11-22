<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Query\ListNotices;

use Modules\Ops\Domain\Model\Notice;

/** A page of a store's notices (ops.md §1.7): `next` is what to ask for the following page, null on the last. */
final readonly class NoticesPage
{
    /**
     * @param  list<Notice>  $notices
     */
    public function __construct(
        public array $notices,
        public ?string $next,
    ) {}
}
