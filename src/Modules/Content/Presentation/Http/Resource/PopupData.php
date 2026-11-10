<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Modules\Content\Application\Moments;
use Modules\Content\Domain\ValueObject\Schedule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One pop-up as its form shows it (content.md §1.10, §4.4 W6); moments in UTC, shown in the store's zone. */
#[TypeScript]
final class PopupData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
        public string $titleAr,
        public ?string $titleEn,
        public string $textAr,
        public ?string $textEn,
        public ?string $buttonAr,
        public ?string $buttonEn,
        public ?string $link,
        public int $delaySeconds,
        public ?string $mediaId,
        public string $state,
        public ?string $startsAt,
        public ?string $endsAt,
        public int $version,
    ) {}

    /**
     * @param  array<string, mixed>  $popup  PopupsHandler::popup()
     */
    public static function fromFields(array $popup): self
    {
        $text = static fn (string $key): ?string => is_string($popup[$key] ?? null) ? $popup[$key] : null;
        $moment = static fn (string $key): ?\DateTimeImmutable => $text($key) === null ? null : new \DateTimeImmutable((string) $text($key));
        $state = (new Schedule((bool) ($popup['is_scheduled'] ?? false), $moment('starts_at'), $moment('ends_at')))->state(Moments::now())->value;

        return new self(
            (string) $text('id'),
            (string) $text('name'),
            (string) $text('title_ar'),
            $text('title_en'),
            (string) $text('text_ar'),
            $text('text_en'),
            $text('button_ar'),
            $text('button_en'),
            $text('link'),
            (int) ($popup['delay_seconds'] ?? 8),
            $text('media_id'),
            $state,
            $text('starts_at'),
            $text('ends_at'),
            (int) ($popup['version'] ?? 0),
        );
    }
}
