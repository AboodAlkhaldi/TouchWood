<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\TranslateText;

use InvalidArgumentException;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Text\MarkText;
use Modules\Content\Application\Text\TranslationLimits;
use Modules\Content\Application\Text\TranslationPieces;
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
 * (`TranslationPieces`), its marks dropped (§1.3 (b)) until the real account says whether they survive in
 * Arabic, and the blocks that lost bold or a link are counted. A failure keeps what is in the box: it is a
 * refusal, in the reason's words (`TranslationUnavailable`), never the service's own.
 */
final readonly class TranslateTextHandler
{
    /**
     * Whether marks are sent with tag handling (§1.3 (a)) rather than dropped (b). (b) until the first build
     * step's test on the real account (PARKED for the owner, plan §S P1).
     */
    public const bool KEEP_MARKS = false;

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
        $characters = array_sum(array_map(mb_strlen(...), $pieces));

        $this->limits->count($this->person(), $characters);

        try {
            $translated = $this->translator->translate($pieces, 'ar', 'en', self::KEEP_MARKS);
        } catch (TranslationFailed $failed) {
            throw new TranslationUnavailable(strtolower($failed->reason->value));
        }

        return $this->answer($boxes, $translated);
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
     * @return array{rich: RichText|null, pieces: list<string>, marked: list<bool>}
     *
     * @throws InvalidContentInput
     */
    private static function box(array $box): array
    {
        if (trim($box['text']) === '') {
            throw new InvalidContentInput('text', InvalidContentInput::REQUIRED);
        }

        if (! $box['rich']) {
            return ['rich' => null, 'pieces' => [LatinDigits::of(trim($box['text']))], 'marked' => [false]];
        }

        $text = MarkText::blocks('body', $box['text']);
        $cut = TranslationPieces::of($text);

        if ($cut['pieces'] === []) {
            throw new InvalidContentInput('text', InvalidContentInput::REQUIRED);
        }

        return ['rich' => $text, ...$cut];
    }

    /**
     * @param  list<array{rich: RichText|null, pieces: list<string>, marked: list<bool>}>  $boxes
     * @param  list<string>  $translated
     *
     * @throws TranslationUnavailable
     */
    private function answer(array $boxes, array $translated): TranslatedText
    {
        $texts = [];
        $lost = [];
        $at = 0;

        foreach ($boxes as $box) {
            $count = count($box['pieces']);
            $mine = array_slice($translated, $at, $count);
            $at += $count;

            // An empty answer is no translation of a text that had words.
            if (count($mine) !== $count || in_array('', array_map(trim(...), $mine), true)) {
                throw new TranslationUnavailable(TranslationUnavailable::UNREACHABLE);
            }

            if ($box['rich'] === null) {
                $texts[] = LatinDigits::of(trim($mine[0]));
                $lost[] = 0;

                continue;
            }

            // Text the service returns is treated as typed text: the same limits, the same checks (§1.3).
            try {
                $texts[] = MarkText::marks(TranslationPieces::rebuild('body', $box['rich'], $mine));
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
