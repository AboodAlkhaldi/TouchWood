<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The store's footer as W1's Footer tab shows it (content.md §1.5): a line about the store, phone, email, an address
 * line, the copyright line, and up to 3 columns of up to 8 links, `[{title: {ar, en}, links: [{text: {ar, en}, link}]}]`.
 */
#[TypeScript]
final class FooterData extends Data
{
    /**
     * @param  list<array<string, mixed>>  $columns
     */
    public function __construct(
        public ?string $aboutAr,
        public ?string $aboutEn,
        public ?string $phone,
        public ?string $email,
        public ?string $addressAr,
        public ?string $addressEn,
        public ?string $copyrightAr,
        public ?string $copyrightEn,
        public array $columns,
        public int $version,
    ) {}

    /**
     * @param  array<string, mixed>  $footer  HomePageHandler::footer()
     */
    public static function fromRead(array $footer): self
    {
        $text = static fn (string $key): ?string => is_string($footer[$key] ?? null) ? $footer[$key] : null;
        $columns = is_array($footer['columns'] ?? null) ? array_values(array_filter($footer['columns'], 'is_array')) : [];

        /** @var list<array<string, mixed>> $columns */
        return new self($text('about_ar'), $text('about_en'), $text('phone'), $text('email'), $text('address_ar'), $text('address_en'), $text('copyright_ar'), $text('copyright_en'), $columns, (int) ($footer['version'] ?? 0));
    }
}
