<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One home section (content.md §1.4, §4.4 W1): its type, shown or hidden, the version its form saves at, and what staff
 * filled in it - words only, every pair `{ar, en}` (`HomeSectionSettings`).
 */
#[TypeScript]
final class HomeSectionData extends Data
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public string $id,
        public string $type,
        public bool $isVisible,
        public int $version,
        public array $settings,
        /** Translate filled its English and nobody has ticked that they read it: Show asks first (content.md §1.3, P14). */
        public bool $translationUnread = false,
    ) {}

    /**
     * @param  array<string, mixed>  $section  HomePageHandler::sections()' rows, or section()
     */
    public static function fromRead(array $section): self
    {
        $settings = is_array($section['settings'] ?? null) ? $section['settings'] : [];

        /** @var array<string, mixed> $settings */
        return new self((string) ($section['id'] ?? ''), (string) ($section['type'] ?? ''), (bool) ($section['isVisible'] ?? false), (int) ($section['version'] ?? 0), $settings, ($section['translationUnread'] ?? false) === true);
    }
}
