<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\TranslateText;

/**
 * What one press gives back (content.md §1.3): each box's English, in the boxes' order, written as the box
 * is typed (a rich box in the editor's marks), its bold and links kept (§1.3 (a)), and how many blocks lost
 * them on the way when the service's answer did not hold them - the editor says so under the box: "2
 * paragraphs lost their bold or links in translation. Add them again." Nothing is saved: the person reads
 * it, fixes it, and saves.
 */
final readonly class TranslatedText
{
    /**
     * @param  list<string>  $texts
     * @param  list<int>  $lostMarks  per box, the blocks that held bold or a link and came back plain: 0 but
     *                                for a box whose answer did not hold the tags sent
     */
    public function __construct(
        public array $texts,
        public array $lostMarks,
    ) {}
}
