<?php

declare(strict_types=1);

namespace Modules\Content\Application\Redirects;

use Modules\Content\Application\Moments;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Content\Domain\ValueObject\RedirectPath;

/**
 * The redirects as a visitor meets them (content.md §1.15): **asked only where nothing else would answer**, so no
 * page of the shop pays a query. An address on no line is an ordinary not found: no guessing, no forward to a
 * category. Reads nothing but the path it is given, and no store or session.
 */
final readonly class RedirectLookup
{
    public function __construct(
        private Redirects $redirects,
    ) {}

    /**
     * @param  string  $asked  the address as asked, decoded (`UnmatchedAddress::answer`'s path)
     * @return array{old: string, new: string}|null the line it is on, as kept
     */
    public function find(string $asked): ?array
    {
        $old = RedirectPath::asked($asked);
        $new = $old === null ? null : $this->redirects->newPathOf($old);

        return $old === null || $new === null ? null : ['old' => $old, 'new' => $new];
    }

    /** One more visit of the line and its moment, in one small write - made once the answer is sent. Bots count too. */
    public function counted(string $oldPath): void
    {
        $this->redirects->countVisit($oldPath, Moments::now());
    }
}
