<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Messages;

use Illuminate\Contracts\Config\Repository as Config;
use LogicException;
use Modules\Access\Application\Staff\StaffLinks;

/**
 * Under /admin, which Platform reserves: never mistaken for a store. Built on APP_URL, never on the
 * request's Host header, which the sender chooses: a reset link must not point anywhere else
 * (review of step 3b).
 */
final readonly class UrlStaffLinks implements StaffLinks
{
    public function __construct(
        private Config $config,
    ) {}

    public function invitation(string $token): string
    {
        return $this->link('/admin/invitation/'.$token);
    }

    public function emailChange(string $token): string
    {
        return $this->link('/admin/email-change/'.$token);
    }

    public function passwordReset(string $token): string
    {
        return $this->link('/admin/password/reset/'.$token);
    }

    private function link(string $path): string
    {
        $root = $this->config->get('app.url');

        if (! is_string($root) || $root === '') {
            throw new LogicException('APP_URL must be set: every staff link is built on it.');
        }

        return rtrim($root, '/').$path;
    }
}
