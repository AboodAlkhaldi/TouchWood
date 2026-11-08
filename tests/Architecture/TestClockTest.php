<?php

declare(strict_types=1);

/*
| Every non-browser test runs at TestCase::FROZEN_AT (#335). PHP's own time() doesn't know that: compared with anything the
| app stamped, it is days away. BrevoFailuresTest looped on it and held main's CI for hours (#348). Browser tests stay on
| the real clock on purpose, so they are not read here.
*/

/**
 * @return int the calls of PHP's own time() in this code; a method of that name, or the word in a string, is not one
 */
function testClockCallsIn(string $code): int
{
    $tokens = array_values(array_filter(
        token_get_all($code),
        static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $calls = 0;

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true) || ltrim(strtolower($token[1]), '\\') !== 'time' || ($tokens[$index + 1] ?? '') !== '(') {
            continue;
        }

        $before = $tokens[$index - 1] ?? null;

        if (! is_array($before) || ! in_array($before[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            $calls++;
        }
    }

    return $calls;
}

it('finds PHP\'s own clock', function (string $code) {
    expect(testClockCallsIn("<?php {$code}"))->toBe(1);
})->with([
    'plain' => ['$at = time();'],
    'in a comparison' => ['if ($due <= time()) {}'],
    'from the root' => ['$at = \\time();'],
    'with a space' => ['$at = time ();'],
]);

it('leaves alone what isn\'t PHP\'s own clock', function (string $code) {
    expect(testClockCallsIn("<?php {$code}"))->toBe(0);
})->with([
    'a method' => ['$page->time();'],
    'a null-safe method' => ['$page?->time();'],
    'a static method' => ['Clock::time();'],
    'declaring one' => ['class A { public function time() {} }'],
    'in a string' => ['$js = "time()";'],
    'in a comment' => ['// time()'],
    'the frozen clock' => ['$at = CarbonImmutable::now()->getTimestamp();'],
]);

it('finds no call of PHP\'s own clock in a test on the frozen clock', function () {
    $root = dirname(__DIR__);
    $scanned = 0;
    $found = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        $path = str_replace('\\', '/', $file->getPathname());

        if ($file->getExtension() !== 'php' || str_contains($path, '/tests/Browser/')) {
            continue;
        }

        $scanned++;

        if (testClockCallsIn((string) file_get_contents($path)) > 0) {
            $found[] = $path;
        }
    }

    // Guards against a moved directory making this test pass over nothing.
    expect($scanned)->toBeGreaterThan(500)
        ->and($found)->toBe([]);
});
