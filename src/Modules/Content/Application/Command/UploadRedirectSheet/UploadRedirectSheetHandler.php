<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\UploadRedirectSheet;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Redirects\RedirectRules;
use Modules\Content\Application\Redirects\RedirectSheet;
use Modules\Content\Application\Redirects\SheetReader;
use Modules\Content\Domain\Exception\SheetRefused;
use Modules\Content\Domain\Repository\Redirects;
use Modules\Content\Domain\Repository\RedirectUploads;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * Reads a redirect sheet and checks it, line by line, **writing nothing to the redirects** (content.md §1.15, W11b):
 * each line New, Same as Now, Change, or Refused and why. Only Apply Sheet writes it.
 *
 * **Refused whole** (`SheetRefused`): not xlsx or csv, over 2 MB, a first row that is not the two headings, no line,
 * or over 5,000 lines (§9.3 #7). The sheet's file is never kept, only what was found in it, for 24 hours.
 */
final readonly class UploadRedirectSheetHandler
{
    public const string PERMISSION = ContentPermissions::REDIRECT_MANAGE;

    public const int MAX_BYTES = 2 * 1024 * 1024;

    public const int MAX_LINES = 5000;

    /** The headings, as the first row must have them (case and spaces aside). */
    public const array HEADINGS = ['old address', 'new address'];

    public function __construct(
        private Authorizer $authorizer,
        private SheetReader $reader,
        private RedirectRules $rules,
        private Redirects $redirects,
        private RedirectUploads $uploads,
        private ContentAccess $access,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return string the checked sheet's id, for its page and Apply Sheet
     *
     * @throws SheetRefused|Unauthorized
     */
    public function handle(UploadRedirectSheet $command): string
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $type = strtolower(pathinfo($command->fileName, PATHINFO_EXTENSION));

        if (! in_array($type, [SheetReader::XLSX, SheetReader::CSV], true)) {
            throw new SheetRefused(SheetRefused::TYPE);
        }

        if ($command->bytes > self::MAX_BYTES || (is_file($command->path) && filesize($command->path) > self::MAX_BYTES)) {
            throw new SheetRefused(SheetRefused::TOO_LARGE);
        }

        // The headings and one row past the limit: enough to know a sheet is over it.
        $rows = $this->reader->rows($command->path, $type, self::MAX_LINES + 2);
        $headings = array_map(static fn (string $cell): string => strtolower((string) preg_replace('/\s+/u', ' ', trim($cell))), array_slice($rows[0]['cells'] ?? [], 0, 2));

        if ($headings !== self::HEADINGS) {
            throw new SheetRefused(SheetRefused::HEADINGS);
        }

        $lines = array_slice($rows, 1);

        if ($lines === []) {
            throw new SheetRefused(SheetRefused::EMPTY);
        }

        if (count($lines) > self::MAX_LINES) {
            throw new SheetRefused(SheetRefused::TOO_MANY_LINES);
        }

        $checked = (new RedirectSheet($this->rules, $this->redirects))->check(array_map(static fn (array $row): array => [
            'line' => $row['row'],
            'old' => $row['cells'][0] ?? '',
            'new' => $row['cells'][1] ?? '',
        ], $lines));

        $id = strtolower((string) Str::ulid());
        $now = Moments::now();

        $this->db->transaction(function () use ($id, $checked, $now): void {
            $this->uploads->pruneBefore($now->modify('-24 hours'));
            $this->uploads->add($id, $checked, $this->access->staffId(), $now);
        });

        return $id;
    }
}
