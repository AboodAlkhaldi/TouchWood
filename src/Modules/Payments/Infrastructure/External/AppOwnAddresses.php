<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure\External;

use Illuminate\Contracts\Config\Repository;
use Modules\Payments\Application\Gateway\OwnAddresses;
use Modules\Payments\Public\Enums\PaymentMode;

final readonly class AppOwnAddresses implements OwnAddresses
{
    public function __construct(
        private Repository $config,
    ) {}

    public function domain(): string
    {
        return strtolower((string) parse_url($this->root(), PHP_URL_HOST));
    }

    public function noticeUrl(string $storeId, PaymentMode $mode): string
    {
        return $this->root().'/payments/notice/'.$storeId.'/'.strtolower($mode->value);
    }

    /** The configured address, never the request's: these go to MyFatoorah, and from jobs too. */
    private function root(): string
    {
        $url = $this->config->get('app.url');

        return is_string($url) ? rtrim($url, '/') : '';
    }
}
