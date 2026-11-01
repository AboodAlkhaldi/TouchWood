<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Messages;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory as Views;
use LogicException;
use Modules\Ops\Application\Alerts\EventSender;
use Modules\Access\Public\Dto\CustomerDto;
use Modules\Ops\Domain\ValueObject\WrittenText;
use Modules\Ops\Public\Enums\MessageKind;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * The emails of step 7 (ops.md §1.7-§1.9), in the recipient's language, from the store's name: what
 * staff wrote, with its page of the shop when it has one; every **marketing** email with its
 * unsubscribe link, which switches the consent off with no sign-in (§1.3); and the newsletter's
 * confirmation to a guest.
 *
 * The frame's words are at `ops::messages.{kind}` and `ops::messages.unsubscribe`.
 */
final readonly class WrittenEmails
{
    public function __construct(
        private Translator $translator,
        private Views $views,
        private Config $config,
        private UrlGenerator $urls,
    ) {}

    public function toCustomer(MessageKind $kind, CustomerDto $customer, StoreDto $store, WrittenText $text, bool $offers): OutgoingEmail
    {
        $locale = self::locale($customer->locale);
        $values = [
            'name' => $customer->firstName,
            'store' => $store->name->in($locale),
            'title' => $locale === 'ar' ? $text->titleAr : $text->titleEn,
            'line' => $locale === 'ar' ? $text->bodyAr : $text->bodyEn,
        ];
        $link = $text->link === null ? null : $this->root().self::shopPath($store, $locale, $text->link);
        // Signed on its path alone (`signed:relative`), on the shop's own address. The same address
        // takes the mail client's one-click POST (RFC 8058): every marketing email says so.
        $unsubscribe = $offers ? $this->root().$this->urls->signedRoute('storefront.ops.unsubscribe', ['store' => $store->code, 'locale' => $locale, 'customer' => strtolower($customer->id)], null, false) : null;
        $headers = $unsubscribe === null ? [] : ['List-Unsubscribe' => "<{$unsubscribe}>", 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];

        return $this->email($kind, $values, $locale, $store, $customer->email, trim($customer->firstName.' '.$customer->lastName), $link, $unsubscribe, headers: $headers);
    }

    /** The guest's confirmation: its link, and the signed link that takes them off again later (§3). */
    public function newsletterConfirmation(string $email, string $locale, StoreDto $store, string $link, string $leave): OutgoingEmail
    {
        $locale = self::locale($locale);

        return $this->email(MessageKind::NewsletterConfirmation, ['store' => $store->name->in($locale)], $locale, $store, $email, null, $link, $leave, 'ops::messages.newsletter_confirmation.leave');
    }

    /** A page of the shop, in the store and the language: "/sa/ar/categories/chairs". */
    public static function shopPath(StoreDto $store, string $locale, string $page): string
    {
        return '/'.$store->code.'/'.self::locale($locale).'/'.ltrim($page, '/');
    }

    /** @return 'ar'|'en' */
    private static function locale(string $locale): string
    {
        return $locale === 'ar' ? 'ar' : 'en';
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, string>  $headers
     */
    private function email(MessageKind $kind, array $values, string $locale, StoreDto $store, string $to, ?string $toName, ?string $link, ?string $unsubscribe, string $leaveWords = 'ops::messages.unsubscribe', array $headers = []): OutgoingEmail
    {
        $lines = array_values(array_map(
            static fn (mixed $line): string => (string) $line,
            (array) $this->translator->get("ops::messages.{$kind->value}.lines", $values, $locale),
        ));
        $action = $link === null ? null : (string) $this->translator->get("ops::messages.{$kind->value}.action", $values, $locale);
        $leave = $unsubscribe === null ? null : [
            'text' => (string) $this->translator->get("{$leaveWords}.text", $values, $locale),
            'action' => (string) $this->translator->get("{$leaveWords}.action", $values, $locale),
        ];
        $brand = $store->name->in($locale);

        return new OutgoingEmail(
            (string) $this->config->get('mail.from.address'),
            $brand,
            $to,
            $toName,
            (string) $this->translator->get("ops::messages.{$kind->value}.subject", $values, $locale),
            $this->views->make('ops::mail.written', [
                'lines' => $lines,
                'action' => $action,
                'link' => $link,
                'leave' => $leave,
                'unsubscribe' => $unsubscribe,
                'locale' => $locale,
                'brand' => $brand,
                'logo' => $this->root().'/'.EventSender::LOGO,
                'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            ])->render(),
            implode("\n\n", [
                ...$lines,
                ...($link === null ? [] : ["{$action}: {$link}"]),
                ...($leave === null ? [] : ["{$leave['text']} {$leave['action']}: {$unsubscribe}"]),
            ]),
            $headers,
        );
    }

    /** The shop's own address - APP_URL, never the request's host. */
    private function root(): string
    {
        $root = $this->config->get('app.url');

        if (! is_string($root) || $root === '') {
            throw new LogicException('APP_URL must be set: every customer link is built on it.');
        }

        return rtrim($root, '/');
    }
}
