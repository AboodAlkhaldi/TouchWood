<?php

declare(strict_types=1);

namespace Modules\Platform\Domain\ValueObject;

use Modules\Platform\Domain\Exception\MissingTranslation;

/**
 * An Arabic and English pair where both are required (handoff §5.2: no fallback on names).
 */
final readonly class TranslatedText
{
    private function __construct(
        public string $ar,
        public string $en,
    ) {}

    /**
     * @param  string  $attribute  which attribute this is, for the error when a value is missing
     */
    public static function of(string $ar, string $en, string $attribute): self
    {
        $ar = trim($ar);
        $en = trim($en);

        if ($ar === '' || $en === '') {
            throw new MissingTranslation($attribute);
        }

        return new self($ar, $en);
    }

    public function equals(self $other): bool
    {
        return $this->ar === $other->ar && $this->en === $other->en;
    }
}
