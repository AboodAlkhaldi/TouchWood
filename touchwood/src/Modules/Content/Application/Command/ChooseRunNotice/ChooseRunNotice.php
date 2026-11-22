<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\ChooseRunNotice;

/**
 * The offer notice one store's run sends when it goes live (content.md §1.7 #10; W4b's Notice card): one of that
 * store's draft notices in Ops, or null for none. `version` is the campaign's, as the form read it.
 */
final readonly class ChooseRunNotice
{
    public function __construct(
        public string $runId,
        public int $version,
        public ?string $noticeId,
    ) {}
}
