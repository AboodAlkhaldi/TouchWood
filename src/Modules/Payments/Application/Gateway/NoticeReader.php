<?php

declare(strict_types=1);

namespace Modules\Payments\Application\Gateway;

/**
 * The gateway's notice, read (payments.md §1.2, §5): only ever a signal to ask (rule 1).
 */
interface NoticeReader
{
    /**
     * @param  list<string>  $secrets  every secret that may have signed it (rule 14)
     */
    public function read(string $body, ?string $signature, #[\SensitiveParameter] array $secrets): NoticeReading;
}
