<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\TranslateText;

/**
 * What one press gives back (content.md §1.3): each box's English, in the boxes' order, written as the box
 * is typed (a rich box in the editor's marks), and how many blocks lost their bold or links on the way - the
 * editor says so under the box: "2 paragraphs lost their bold or links in translation. Add them again."
 * Nothing is saved: the person reads it, fixes it, and saves.
 */
final readonly class TranslatedText
{
    /**
     * @param  list<string>  $texts
     * @param  list<int>  $lostMarks  per box, the blocks that held bold or a link and came back plain
     */
    public function __construct(
        public array $texts,
        public array $lostMarks,
    ) {}
}
