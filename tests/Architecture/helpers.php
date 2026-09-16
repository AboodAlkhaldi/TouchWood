<?php

declare(strict_types=1);

/*
| Shared by the architecture tests.
*/

/**
 * Modules that contain PHP code. Empty modules are skipped, so architecture tests only count
 * checks that can actually fail.
 *
 * @return list<string>
 */
function modulesWithCode(): array
{
    $modules = [];

    foreach (glob(dirname(__DIR__, 2).'/src/Modules/*', GLOB_ONLYDIR) ?: [] as $directory) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() === 'php') {
                $modules[] = basename($directory);

                break;
            }
        }
    }

    return $modules;
}

/**
 * The PHP code of a file with comments removed, so a rule is never satisfied — or broken — by
 * text inside a comment.
 */
function codeWithoutComments(string $path): string
{
    $code = '';

    foreach (PhpToken::tokenize((string) file_get_contents($path)) as $token) {
        if (! $token->is([T_COMMENT, T_DOC_COMMENT])) {
            $code .= $token->text;
        }
    }

    return $code;
}

/**
 * Laravel's global helper functions. Arch expectations see a call to one only by its bare name,
 * so importing nothing from Illuminate is not enough to prove code is framework-free.
 */
const LARAVEL_HELPERS = [
    'app', 'resolve', 'config', 'env', 'now', 'today', 'abort', 'abort_if', 'abort_unless',
    'response', 'request', 'redirect', 'back', 'view', 'session', 'cookie', 'route', 'url',
    'trans', '__', 'event', 'dispatch', 'cache', 'logger', 'info', 'report', 'auth', 'collect',
    'validator',
];

/**
 * The subset of helpers that belong to HTTP.
 */
const LARAVEL_HTTP_HELPERS = [
    'abort', 'abort_if', 'abort_unless', 'response', 'request', 'redirect', 'back', 'view',
    'session', 'cookie', 'route', 'url',
];
