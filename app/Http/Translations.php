<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Contracts\Translation\Translator;

/**
 * The words a page was asked to carry (frontend.md §1.5).
 *
 * There are no frontend translation files. A screen's text lives in Laravel's lang files, beside the
 * text the mailer and the SMS gateway already use, and each page names the files it needs. This
 * flattens those files into one map of `group.key` to a line, in the page's language, and the page
 * carries only that - never the whole dictionary of the system.
 *
 * Framework glue: it knows about Laravel's translator and about nothing else. Which files a page
 * needs is the page's business.
 */
final readonly class Translations
{
    public function __construct(
        private Translator $translator,
    ) {}

    /**
     * @param  list<string>  $groups  e.g. ['access::auth', 'admin'] - a namespaced file, or an
     *                                application one
     * @return array<string, string>
     */
    public function of(array $groups, string $locale): array
    {
        $lines = [];

        foreach ($groups as $group) {
            $file = $this->translator->get($group, [], $locale);

            // A file that does not exist comes back as its own name. Better to carry nothing than
            // to carry the string "access::auth" as if it were a translation; the test over keys
            // (tests/Architecture/TranslationKeysTest) is what catches the mistake.
            if (! is_array($file)) {
                continue;
            }

            foreach ($this->flatten($file, $group) as $key => $line) {
                $lines[$key] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param  array<array-key, mixed>  $file
     * @return array<string, string>
     */
    private function flatten(array $file, string $prefix): array
    {
        $lines = [];

        foreach ($file as $key => $value) {
            $full = $prefix.'.'.$key;

            if (is_array($value)) {
                foreach ($this->flatten($value, $full) as $nested => $line) {
                    $lines[$nested] = $line;
                }

                continue;
            }

            if (is_string($value)) {
                $lines[$full] = $value;
            }
        }

        return $lines;
    }
}
