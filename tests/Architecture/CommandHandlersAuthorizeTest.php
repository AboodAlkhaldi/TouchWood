<?php

/*
| Handoff §19: every command handler asserts a permission before doing anything.
*/

it('checks a permission in every command handler', function () {
    $handlers = glob(dirname(__DIR__, 2).'/src/Modules/*/Application/Command/*/*Handler.php') ?: [];
    $unprotected = [];

    foreach ($handlers as $handler) {
        if (preg_match('/->authorize\s*\(/', (string) file_get_contents($handler)) !== 1) {
            $unprotected[] = str_replace('\\', '/', substr($handler, strlen(dirname(__DIR__, 2)) + 1));
        }
    }

    expect($unprotected)->toBe([]);
});
