<?php

declare(strict_types=1);

namespace Modules\Content\Application\Command\SaveFooter;

use Illuminate\Database\ConnectionInterface;
use Modules\Content\Application\ContentAccess;
use Modules\Content\Application\ContentAudit;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\EditCheck;
use Modules\Content\Application\Moments;
use Modules\Content\Domain\Exception\ContentError;
use Modules\Content\Domain\Exception\InvalidContentInput;
use Modules\Content\Domain\Model\Footer;
use Modules\Content\Domain\Repository\FrameItems;
use Modules\Content\Domain\ValueObject\Words;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;

/**
 * Saves a store's footer (content.md §1.5). **Refused on save without both languages** of every line typed (§1.1
 * rule 2: there is no Publish step), up to 3 columns of up to 8 links, each link a shop page or an https:// address.
 * The phone and email are checked for their shape only. Refused over a newer version (§1.1 rule 12).
 *
 * The lengths are the build's picks: about 200, address 200, copyright 120, a column's title and a link's text 40.
 */
final readonly class SaveFooterHandler
{
    public const string PERMISSION = ContentPermissions::HOMEPAGE_MANAGE;

    public const int MAX_COLUMNS = 3;

    public const int MAX_LINKS = 8;

    public function __construct(
        private ContentAccess $access,
        private FrameItems $frame,
        private EditCheck $check,
        private ConnectionInterface $db,
        private PlatformApi $platform,
        private ContentCache $cache,
    ) {}

    /**
     * @throws ContentError|Unauthorized
     */
    public function handle(SaveFooter $command): void
    {
        $store = $this->access->authorize(self::PERMISSION, $command->storeId);
        $staffId = $this->access->staffId();

        $about = Words::bothOrNone('about', $command->aboutAr, $command->aboutEn, 200);
        $address = Words::bothOrNone('address', $command->addressAr, $command->addressEn, 200);
        $copyright = Words::bothOrNone('copyright', $command->copyrightAr, $command->copyrightEn, 120);
        $phone = self::phone($command->phone);
        $email = self::email($command->email);
        $columns = self::columns($command->columns);

        $this->db->transaction(function () use ($store, $command, $staffId, $about, $address, $copyright, $phone, $email, $columns): void {
            $footer = $this->frame->footer($store->id, lock: true);
            $this->check->version($command->version, $footer->version, $footer->updatedBy, $footer->updatedAt, $store);
            $saved = new Footer($store->id, $about['ar'], $about['en'], $phone, $email, $address['ar'], $address['en'], $copyright['ar'], $copyright['en'], $columns, $footer->version + 1, $staffId, Moments::now());
            $this->frame->saveFooter($saved);
            $this->platform->recordAudit(ContentAudit::entry('content.footer.saved', 'content.footer', $store->id, $store->id, ContentAudit::diff($footer->fields(), $saved->fields(), ['phone', 'email', 'address_ar', 'address_en'])));
            $this->cache->invalidate();
        }, 3);
    }

    /**
     * @param  list<array{titleAr: string|null, titleEn: string|null, links: list<array{textAr: string|null, textEn: string|null, link: string|null}>}>  $sent
     * @return list<array{title: array{ar: string, en: string}, links: list<array{text: array{ar: string, en: string}, link: string}>}>
     *
     * @throws ContentError
     */
    private static function columns(array $sent): array
    {
        if (count($sent) > self::MAX_COLUMNS) {
            throw new InvalidContentInput('columns', InvalidContentInput::TOO_MANY, values: ['max' => self::MAX_COLUMNS]);
        }

        return array_map(static function (array $column): array {
            if (count($column['links']) > self::MAX_LINKS) {
                throw new InvalidContentInput('column_links', InvalidContentInput::TOO_MANY, values: ['max' => self::MAX_LINKS]);
            }

            return [
                'title' => Words::both('column_title', $column['titleAr'], $column['titleEn'], 40),
                'links' => array_map(static fn (array $link): array => [
                    'text' => Words::both('link_text', $link['textAr'], $link['textEn'], 40),
                    'link' => Words::link('link', $link['link']) ?? throw new InvalidContentInput('link', InvalidContentInput::REQUIRED),
                ], $column['links']),
            ];
        }, $sent);
    }

    /**
     * @throws InvalidContentInput
     */
    private static function phone(?string $phone): ?string
    {
        $phone = Words::line('phone', $phone, 30);

        if ($phone !== null && preg_match('/\A\+?[0-9][0-9 \-]{4,28}\z/', $phone) !== 1) {
            throw new InvalidContentInput('phone', InvalidContentInput::PHONE);
        }

        return $phone;
    }

    /**
     * @throws InvalidContentInput
     */
    private static function email(?string $email): ?string
    {
        $email = Words::line('email', $email, 254);

        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidContentInput('email', InvalidContentInput::EMAIL);
        }

        return $email;
    }
}
