<?php

declare(strict_types=1);

use Modules\Content\Domain\Model\DocumentKind;

/*
| What the audit log calls each Content action (Presentation/lang/{ar,en}/audit.php; content.md §1.1 rule 13). An
| action with no name shows its raw key, so every one Content records is named in both languages. Actions are read
| from the code: written out where the entry is made, or built for each kind of document from its key.
*/

/**
 * @return list<string>
 */
function contentAuditNamesRecorded(): array
{
    $actions = [];
    $root = dirname(__DIR__, 4).'/src/Modules/Content';
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/ContentAudit::entry\\(\\s*'(content\\.[a-z_]+\\.[a-z_]+)'/", (string) file_get_contents($file->getPathname()), $found);
        array_push($actions, ...$found[1]);
    }

    // The documents' (DocumentWriter) and the detached files' (ContentMediaUsage) are built from their kind.
    foreach (DocumentKind::cases() as $kind) {
        foreach (['created', 'changed', 'published', 'unpublished', 'deleted', 'image_detached'] as $verb) {
            $actions[] = "content.{$kind->key()}.{$verb}";
        }
    }

    foreach (['home_section', 'banner', 'popup', 'theme'] as $subject) {
        $actions[] = "content.{$subject}.image_detached";
    }

    // The Show switch names its action by the way it is flipped (ShowHomeSectionHandler).
    array_push($actions, 'content.home_section.shown', 'content.home_section.hidden');

    $actions = array_values(array_unique($actions));
    sort($actions);

    return $actions;
}

it('finds the actions Content records', function () {
    // A guard over files must find something, or it passes over nothing.
    // PR 3's 49, and PR 4's six: the SEO defaults saved and their image detached, a redirect added, changed,
    // deleted and its count reset. PR 6's sixteen: a campaign's eight, a theme's seven and its image detached.
    expect(count(contentAuditNamesRecorded()))->toBe(71)
        ->and(contentAuditNamesRecorded())->toContain('content.banner.scheduled', 'content.home_sections.ordered', 'content.post.published');
});

it('names every action Content records, in Arabic and English, and nothing it does not', function () {
    $named = array_map(static fn (int|string $key): string => "content.{$key}", array_keys(require dirname(__DIR__, 4).'/src/Modules/Content/Presentation/lang/en/audit.php'));
    sort($named);

    expect($named)->toBe(contentAuditNamesRecorded());

    foreach (contentAuditNamesRecorded() as $action) {
        $key = 'content::audit.'.substr($action, strlen('content.'));

        foreach (['ar', 'en'] as $locale) {
            expect(trans($key, [], $locale))->not->toBe($key, "{$action} has no {$locale} name");
        }
    }
});
