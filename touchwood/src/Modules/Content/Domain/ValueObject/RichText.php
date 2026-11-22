<?php

declare(strict_types=1);

namespace Modules\Content\Domain\ValueObject;

use Modules\Content\Domain\Exception\InvalidContentInput;
use Shared\Domain\Text\LatinDigits;

/**
 * A page's, a post's or a project's text in one language (content.md §1.2): **structured, never HTML**, so
 * nothing typed can run as a script, and the shop draws it with its own component from the blocks.
 *
 * Content's own copy of Catalog's `StructuredText` shape (Catalog's is not public, and a class in Shared
 * needs three users, handoff §4.5): paragraphs, headings and lists of runs `{text, bold?}`, extended with a
 * second heading level, numbered lists, links, images and tables:
 *
 *     {"blocks": [
 *         {"type": "heading",   "level": 2, "runs": [{"text": "Installation"}]},
 *         {"type": "paragraph", "runs": [{"text": "See "}, {"text": "our guide", "link": "/pages/guide"}, {"text": "."}]},
 *         {"type": "list",      "ordered": true, "items": [[{"text": "Measure"}], [{"text": "Fit", "bold": true}]]},
 *         {"type": "image",     "ref": 3, "alt": "The cabinet open"},
 *         {"type": "table",     "head": [[{"text": "Size"}], [{"text": "Price"}]], "rows": [[[{"text": "60"}], []]]}
 *     ]}
 *
 * - `level` is 2 (`# `) or 3 (`## `): a page's title is its only level 1. `ordered` and `bold` are written
 *   only when true, so one text has one shape.
 * - A link is a page of the shop without its store and language, kept with its leading slash
 *   ("/categories/chairs"), or a full `https://` address; anything else - `javascript:`, `//`, a
 *   backslash - is refused (§1.1 rule 16).
 * - An image names `ref`, a row of the item's own images table, which holds the file (§1.1 rule 10); its
 *   description is required. Whether the ref resolves is the item's to check, at save.
 * - A table has 2 to 8 columns and 1 to 100 rows, every row as wide as the heading; a cell is one line of
 *   runs and may be empty.
 * - Limits: 100,000 characters (every run's words and every description) and 50 images a document. Runs
 *   side by side with the same marks are joined, so a document read back is the one kept.
 * - Every digit is saved 0-9 (§1.1 rule 3). An empty document - no blocks - is a draft's English: whether
 *   it may be empty is the use case's rule (Arabic to save, English to publish).
 *
 * Refused values name the block they are in (`InvalidContentInput::$block`): the typed text's reader turns
 * it into the line a person sees.
 */
final readonly class RichText
{
    public const int MAX_CHARACTERS = 100_000;

    public const int MAX_IMAGES = 50;

    /**
     * A ceiling on blocks, so a document of empty table cells cannot grow without end. Not in the spec:
     * the build's pick, far above 100,000 characters' worth of real text.
     */
    public const int MAX_BLOCKS = 2_000;

    /** A ceiling on a list's items, as Catalog's. */
    public const int MAX_ITEMS = 100;

    public const int MIN_COLUMNS = 2;

    public const int MAX_COLUMNS = 8;

    public const int MAX_ROWS = 100;

    /** An address's longest. Not in the spec: the build's pick. */
    public const int MAX_LINK = 2_000;

    /**
     * An https address's authority, up to the first "/", "?" or "#": a host - a name, or an address in brackets -
     * with a port of digits at most. No user part, which can make one address read as another
     * (`https://shop@evil.example`; PR 7a review), and no empty host (`https:///x`, `https://@x`). The editors
     * check the same pattern as it is typed (resources/js/components/content/links.ts; link-cases.json).
     */
    private const string HTTPS_AUTHORITY = '~\A(?:\[[^\]/?#@]+\]|[^\[\]/?#@:]+)(?::[0-9]*)?(?:[/?#]|\z)~u';

    /** A line break, a control character, or a line or paragraph separator. */
    private const string LINE_BREAK = '/[\p{Cc}\p{Zl}\p{Zp}]/u';

    /**
     * @param  array{blocks: list<array<string, mixed>>}  $document
     * @param  list<int>  $images
     */
    private function __construct(
        private array $document,
        private int $characters,
        private array $images,
    ) {}

    /**
     * @param  mixed  $document  the decoded value, as it came
     *
     * @throws InvalidContentInput
     */
    public static function of(string $attribute, mixed $document): self
    {
        if (! is_array($document) || array_keys($document) !== ['blocks'] || ! is_array($document['blocks']) || ! array_is_list($document['blocks'])) {
            throw new InvalidContentInput($attribute, InvalidContentInput::FORMAT);
        }

        if (count($document['blocks']) > self::MAX_BLOCKS) {
            throw new InvalidContentInput($attribute, InvalidContentInput::TOO_MANY_BLOCKS, values: ['max' => self::MAX_BLOCKS]);
        }

        $reader = new RichTextReader($attribute);
        $blocks = [];

        foreach ($document['blocks'] as $index => $block) {
            $blocks[] = $reader->block($block, $index);
        }

        return new self(['blocks' => $blocks], $reader->characters, $reader->images);
    }

    /**
     * A value read back from its column, as `toJson()` wrote it, checked again on the way in: a row edited
     * past the code fails loudly here rather than drawing a broken page.
     *
     * @throws InvalidContentInput
     */
    public static function fromJson(string $attribute, string $json): self
    {
        $decoded = json_decode($json, true, 64);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidContentInput($attribute, InvalidContentInput::FORMAT);
        }

        return self::of($attribute, $decoded);
    }

    public static function empty(): self
    {
        return new self(['blocks' => []], 0, []);
    }

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return $this->document;
    }

    /** The exact value as JSON, Arabic left readable: what the column holds. */
    public function toJson(): string
    {
        return json_encode($this->document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function isEmpty(): bool
    {
        return $this->document['blocks'] === [];
    }

    /** Every run's words and every description, counted as the limit counts them. */
    public function characters(): int
    {
        return $this->characters;
    }

    /**
     * The image rows the text names, in the order they come, each once: the item checks each is its own.
     *
     * @return list<int>
     */
    public function imageRefs(): array
    {
        return array_values(array_unique($this->images));
    }

    public function equals(self $other): bool
    {
        return $this->document === $other->document;
    }

    /**
     * Text of one line: not empty, UTF-8, with no line break or control character in it, its digits 0-9.
     *
     * @throws InvalidContentInput
     */
    public static function line(string $attribute, mixed $text, ?int $block = null): string
    {
        if (! is_string($text) || $text === '' || preg_match('//u', $text) !== 1) {
            throw new InvalidContentInput($attribute, InvalidContentInput::FORMAT, block: $block);
        }

        if (preg_match(self::LINE_BREAK, $text) === 1) {
            throw new InvalidContentInput($attribute, InvalidContentInput::ONE_LINE, block: $block);
        }

        return LatinDigits::of($text);
    }

    /**
     * A link as §1.1 rule 16 takes it, or null for one it refuses: a page of the shop, kept with its leading
     * slash, or a full https:// address. A space, a control character or an invisible format character
     * (U+202E right-to-left override, a zero-width space) anywhere in it refuses it: none belongs in an
     * address, and they can make one read as another.
     */
    public static function link(string $address): ?string
    {
        $address = trim($address);

        if ($address === '' || mb_strlen($address) > self::MAX_LINK || preg_match('//u', $address) !== 1
            || str_contains($address, '\\') || preg_match('/[\s\p{Cc}\p{Cf}]/u', $address) === 1) {
            return null;
        }

        if (str_starts_with(strtolower($address), 'https://')) {
            return preg_match(self::HTTPS_AUTHORITY, substr($address, 8)) === 1 ? $address : null;
        }

        // A page of the shop: no scheme, never "//" (another site), the store and language added when drawn.
        $page = '/'.ltrim($address, '/');

        return str_starts_with($address, '//') || str_contains($page, ':') || str_contains($page, '//') ? null : $page;
    }
}
