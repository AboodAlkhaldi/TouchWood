<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Messages;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Access\Application\Staff\StaffLinks;

/**
 * Under /admin, which Platform reserves: never mistaken for a store.
 */
final readonly class UrlStaffLinks implements StaffLinks
{
    public function __construct(
        private UrlGenerator $urls,
    ) {}

    public function invitation(string $token): string
    {
        return $this->urls->to('/admin/invitation/'.$token);
    }

    public function emailChange(string $token): string
    {
        return $this->urls->to('/admin/email-change/'.$token);
    }
}
