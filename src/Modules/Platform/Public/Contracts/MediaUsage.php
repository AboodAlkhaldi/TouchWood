<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Contracts;

use Modules\Platform\Public\Dto\MediaUseDto;

/**
 * Implemented by every module that stores media ids (owner's decision, 2026-09-18). Before media
 * is deleted, Platform asks each module where it uses it. If any use blocks the delete, nothing
 * changes and the delete is refused. Otherwise every module that uses it detaches it, and the
 * media is deleted — all in one transaction.
 *
 * Register the class with MediaUsages. Both methods run inside the delete's transaction, with the
 * media row locked first.
 *
 * Every media id a module stores sits in its own column, or a small link table, with an
 * ON DELETE RESTRICT foreign key to platform.media — never inside JSON (owner's decision,
 * 2026-09-18). Only then does the lock stop a new reference from appearing during the delete, and
 * only then does the foreign key catch a reference the module failed to report.
 */
interface MediaUsage
{
    /**
     * Every place this module uses the media; empty when it does not. A use blocks the delete when
     * losing the file would lose a record that must be kept, such as a company's registration
     * document or a bank-transfer receipt.
     *
     * @return list<MediaUseDto>
     */
    public function usesOf(string $mediaId): array;

    /**
     * Removes every reference this module holds to the media: a product loses that photo, a
     * banner its image. Called only when no module blocks the delete.
     *
     * This changes the module's own data, so it checks the acting person's permission for that
     * change itself, in the stores concerned (owner's decision, 2026-09-18), and records its own
     * audit entries and events. Throwing — Unauthorized included — cancels the whole delete.
     */
    public function detach(string $mediaId): void;
}
