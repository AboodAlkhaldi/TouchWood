<?php

declare(strict_types=1);

/*
| Handoff §19: every command handler asserts a permission. Comments are stripped first, so a
| handler cannot pass by mentioning authorize() in a comment.
|
| Query handlers are held to the same rule, with the reads' own idiom allowed: a read that spans
| stores asks which stores the reader holds the permission in (storesWith), and the role reads ask
| GrantRules, rather than authorizing one scope (review of step 7).
*/

require_once __DIR__.'/helpers.php';

it('checks a permission in every command handler', function () {
    $root = dirname(__DIR__, 2);
    $handlers = glob($root.'/src/Modules/*/Application/Command/*/*Handler.php') ?: [];
    $unprotected = [];

    // Guards against a moved directory making this test pass over nothing.
    expect(count($handlers))->toBeGreaterThanOrEqual(60);

    foreach ($handlers as $handler) {
        if (preg_match('/->authorize\s*\(/', codeWithoutComments($handler)) !== 1) {
            $unprotected[] = str_replace('\\', '/', substr($handler, strlen($root) + 1));
        }
    }

    expect($unprotected)->toBe([]);
});

it('checks a permission in every query handler', function () {
    $root = dirname(__DIR__, 2);
    $handlers = glob($root.'/src/Modules/*/Application/Query/*/*Handler.php') ?: [];
    $unprotected = [];

    expect(count($handlers))->toBeGreaterThanOrEqual(8);

    foreach ($handlers as $handler) {
        if (preg_match('/->(authorize|storesWith|requireRoleReader)\s*\(/', codeWithoutComments($handler)) !== 1) {
            $unprotected[] = str_replace('\\', '/', substr($handler, strlen($root) + 1));
        }
    }

    expect($unprotected)->toBe([]);
});

it('does not count authorize() written in a comment', function () {
    $file = (string) tempnam(sys_get_temp_dir(), 'handler');
    file_put_contents($file, "<?php\n// \$this->authorizer->authorize('x');\nfinal class Handler {}\n");

    expect(preg_match('/->authorize\s*\(/', codeWithoutComments($file)))->toBe(0);

    unlink($file);
});
