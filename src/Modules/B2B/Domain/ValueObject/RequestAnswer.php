<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\ValueObject;

/**
 * A draft's answer to one request of the last rejection (b2b.md §1.2, amendment 4): text, or a
 * file — exactly one, of the kind the request asks for. It belongs to the application that gives
 * it, as every value it sends does.
 */
final readonly class RequestAnswer
{
    private function __construct(
        public string $requestId,
        public ?Remark $text,
        public ?string $mediaId,
    ) {}

    /**
     * @param  Remark  $text  at most 1000 characters, line breaks kept, as every remark
     */
    public static function text(string $requestId, Remark $text): self
    {
        return new self(strtolower($requestId), $text, null);
    }

    /**
     * @param  string  $mediaId  a private Platform file (b2b.md §1.4)
     */
    public static function file(string $requestId, string $mediaId): self
    {
        return new self(strtolower($requestId), null, $mediaId);
    }

    public function kind(): RequestKind
    {
        return $this->mediaId !== null ? RequestKind::File : RequestKind::Text;
    }
}
