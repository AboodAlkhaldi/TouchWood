<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\TranslateText;

use InvalidArgumentException;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Text\MarkText;
use Modules\Content\Application\Text\TranslationLimits;
use Modules\Content\Application\Text\TranslationPieces;
use Modules\Content\Application\Text\TranslationTags;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Exception\StoreOff;
use Modules\Content\Domain\Exception\TranslationUnavailable;
use Modules\Content\Domain\ValueObject\RichText;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\TextTranslator;
use Modules\Platform\Public\Contracts\TranslationFailed;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\ActorContext;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\Text\LatinDigits;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Translate** (content.md §1.3): the Arabic of each box asked for, sent to the translator in one call, and
 * its English given back for the person to read. **Nothing is saved**, and no copy is kept.
 *
 * **The permission is the kind's** - whoever may edit that kind of item, in that store (§3) - so an item not
 * saved yet can be translated; a theme's is the Super Admin's, in every store. It declares no `PERMISSION`
 * of its own for that reason, as Ops' staff message takes one of two by its audience. A store that is off is
 * refused to whoever may not switch stores (§1.1 rule 7).
 *
 * Then, in this order, nothing sent until all pass: the server has a token; each box has Arabic (a rich box
 * read as the editor's marks, §1.2); the press is within 20,000 characters, this person's 30 presses a minute
 * and 300,000 characters a day (`TranslationLimits`). A rich box goes **block by block**
 * (`TranslationPieces`), **its bold and links with it** (§1.3 (a)): a press holding any mark is sent with
 * tag handling, every text in it written as XML (`TranslationTags`), and each rich box rebuilt with its marks
 * from the answer. A box whose answer does not hold the tags sent falls back to plain words, its marks
 * dropped and its blocks that held them counted (`lostMarks`); the press goes on. A press with no marks is
 * sent as plain text, as before. A failure keeps what is in the box: it is a refusal, in the reason's words
 * (`TranslationUnavailable`), never the service's own.
 */
final readonly class TranslateTextHandler
{
    /**
     * Whether marks are sent with tag handling (§1.3 (a)) rather than dropped (b): (a), after the owner's
     * test on the real account, 2026-11-10 - Arabic bold came back on the right English words, reordered.
     */
    public const bool KEEP_MARKS = true;

    /**
     * The most bytes one box may hold before it is read: 20,000 letters are at most 80 KB in UTF-8, with
     * room for the editor's marks. The build's pick (PR 2 review).
     */
    public const int MAX_BYTES = 200_000;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private ActorContext $actors,
        private TextTranslator $translator,
        private TranslationLimits $limits,
    ) {}

    /**
     * @throws Unauthorized|InvalidContentInput|StoreOff|TranslationUnavailable
     */
    public function handle(TranslateText $command): TranslatedText
    {
        $permission = ContentPermissions::forKind($command->kind) ?? throw new InvalidContentInput('kind', InvalidContentInput::KIND);

        if ($permission === ContentPermissions::THEME_MANAGE) {
            $this->authorizer->authorize($permission, PermissionScope::allStores());
        } else {
            $this->authorizer->authorize($permission, PermissionScope::store($this->store($command->storeId, $permission)));
            $this->refuseOffStore($command->storeId);
        }

        if (! $this->translator->isReady()) {
            throw new TranslationUnavailable(TranslationUnavailable::NOT_SET);
        }

        if ($command->boxes === []) {
            throw new InvalidContentInput('text', InvalidContentInput::REQUIRED);
        }

        // Every box read before anything is counted or sent: one written wrong refuses the press.
        $boxes = array_map(self::box(...), $command->boxes);
        $pieces = array_merge(...array_map(static fn (array $box): array => $box['pieces'], $boxes));

        // Tag handling only for a press that holds a mark: then every text in the call is read as XML by the
        // service, so every one is sent written as XML. A press with none goes as plain text, as before.
        $tagged = self::tagged($boxes, self::KEEP_MARKS);
        $sent = $tagged ? array_merge(...array_map(static fn (array $box): array => $box['tagged'], $boxes)) : $pieces;
        $this->limits->count($this->person(), $pieces, $sent);

        try {
            $translated = $this->translator->translate($sent, 'ar', 'en', $tagged);
        } catch (TranslationFailed $failed) {
            throw new TranslationUnavailable(strtolower($failed->reason->value));
        }

        return $this->answer($boxes, $translated, $tagged);
    }

    /**
     * @throws Unauthorized
     */
    private function store(?string $storeId, string $permission): StoreId
    {
        try {
            return StoreId::fromString((string) $storeId);
        } catch (InvalidArgumentException) {
            // A malformed store is no store the person holds anything in.
            throw new Unauthorized($permission);
        }
    }

    /**
     * @throws InvalidContentInput|StoreOff
     */
    private function refuseOffStore(?string $storeId): void
    {
        $store = $this->platform->store(StoreId::fromString((string) $storeId)) ?? throw new InvalidContentInput('store', InvalidContentInput::STORE);

        if (! $store->isActive && $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) === []) {
            throw new StoreOff;
        }
    }

    /**
     * A box's pieces to send: a plain box is one, a rich one is each of its blocks' (§1.3).
     *
     * @param  array{text: string, rich: bool}  $box
     * @return array{rich: RichText|null, pieces: list<string>, marked: list<bool>, tagged: list<string>}
     *
     * @throws InvalidContentInput
     */
    private static function box(array $box): array
    {
        // Read as typed only when it can be: not UTF-8 is no text, and past the bytes a press may carry it is
        // refused before anything is parsed (20,000 letters with their marks need far less).
        if (strlen($box['text']) > self::MAX_BYTES) {
            throw new InvalidContentInput('text', InvalidContentInput::TOO_LARGE);
        }

        if (preg_match('//u', $box['text']) !== 1) {
            throw new InvalidContentInput('text', InvalidContentInput::FORMAT);
        }

        if (trim($box['text']) === '') {
            throw new InvalidContentInput('text', InvalidContentInput::REQUIRED);
        }

        if (! $box['rich']) {
            $words = LatinDigits::of(trim($box['text']));

            return ['rich' => null, 'pieces' => [$words], 'marked' => [false], 'tagged' => [TranslationTags::escape($words)]];
        }

        $text = MarkText::blocks('body', $box['text']);
        $cut = TranslationPieces::of($text);

        if ($cut['pieces'] === []) {
            throw new InvalidContentInput('text', InvalidContentInput::REQUIRED);
        }

        return ['rich' => $text, ...$cut];
    }

    /**
     * Whether the press goes with tag handling: marks are kept (§1.3 (a)) and one of its pieces holds one.
     *
     * @param  list<array{rich: RichText|null, pieces: list<string>, marked: list<bool>, tagged: list<string>}>  $boxes
     */
    private static function tagged(array $boxes, bool $keepMarks): bool
    {
        return $keepMarks && in_array(true, array_merge(...array_map(static fn (array $box): array => $box['marked'], $boxes)), true);
    }

    /**
     * @param  list<array{rich: RichText|null, pieces: list<string>, marked: list<bool>, tagged: list<string>}>  $boxes
     * @param  list<string>  $translated
     * @param  bool  $tagged  whether the call went as XML, with tag handling
     *
     * @throws TranslationUnavailable
     */
    private function answer(array $boxes, array $translated, bool $tagged): TranslatedText
    {
        $texts = [];
        $lost = [];
        $at = 0;

        foreach ($boxes as $box) {
            $count = count($box['pieces']);
            $mine = array_slice($translated, $at, $count);
            $at += $count;

            if (count($mine) !== $count) {
                throw new TranslationUnavailable(TranslationUnavailable::UNREACHABLE);
            }

            // Each answer's words: in a call sent as XML, read back from XML with every tag taken out.
            $words = $tagged ? array_map(TranslationTags::plain(...), $mine) : $mine;
            $empty = in_array('', array_map(trim(...), $words), true);

            // An answer of no words is no translation of a text that had words: the press is refused, but for
            // a rich box sent as XML, which falls back below (the words checked, never the raw XML).
            if ($empty && ! ($tagged && $box['rich'] !== null)) {
                throw new TranslationUnavailable(TranslationUnavailable::UNREACHABLE);
            }

            if ($box['rich'] === null) {
                $texts[] = LatinDigits::of(trim($words[0]));
                $lost[] = 0;

                continue;
            }

            // The marks rebuilt from the tags sent; an answer that does not hold them drops this box's marks.
            $kept = $tagged ? TranslationPieces::rebuildMarked('body', $box['rich'], $mine) : null;

            if ($kept !== null) {
                $texts[] = MarkText::marks($kept);
                $lost[] = 0;

                continue;
            }

            // Text the service returns is treated as typed text: the same limits, the same checks (§1.3).
            // A block whose answer held no words keeps its own Arabic words, plain, for the person to see and
            // type over (the build's pick, PR #372 review): the box falls back rather than the press failing.
            try {
                $plain = array_map(static fn (string $answer, string $source): string => trim($answer) === '' ? $source : $answer, $words, $box['pieces']);
                $texts[] = MarkText::marks(TranslationPieces::rebuild('body', $box['rich'], $plain));
            } catch (InvalidContentInput) {
                throw new TranslationUnavailable(TranslationUnavailable::UNREACHABLE);
            }

            $lost[] = count(array_filter($box['marked']));
        }

        return new TranslatedText($texts, $lost);
    }

    /** Who pressed: the limits are per person. */
    private function person(): string
    {
        $actor = $this->actors->current();

        return $actor->type->value.':'.($actor->id ?? 'none');
    }
}
