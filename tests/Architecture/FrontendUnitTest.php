<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
| The screens' own unit tests (tests/Frontend/*.test.ts) - first, the rules every box checks as it is
| typed (resources/js/lib/checks.ts; frontend.md §1.7) - run by Node's built-in runner through
| tests/Frontend/run.mjs, here inside the PHP suite so that `composer check` and CI run them with
| everything else. No JavaScript test framework is installed for them: Vite bundles each file for
| Node, and node:test runs it.
|
| The runner's report is TAP; its summary must say that tests ran, and that none failed - a runner
| that found nothing would otherwise pass in silence.
*/

it('passes every unit test of the screens', function () {
    $process = new Process(['node', 'tests/Frontend/run.mjs'], dirname(__DIR__, 2), timeout: 120);
    $process->run();
    $report = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $report);

    preg_match('/^# pass (\d+)$/m', $report, $passed);
    preg_match('/^# fail (\d+)$/m', $report, $failed);

    // Something ran: the rules alone have more than twenty cases.
    expect((int) ($passed[1] ?? 0))->toBeGreaterThan(20, $report)
        ->and($failed[1] ?? null)->toBe('0', $report);
});

/*
| The words of every rule a box can break, in both languages. lib/checks.ts names a rule, and its
| sentence is read as `ui.check.{rule}` - a key built from a variable, which TranslationKeysTest can
| only check as "something under ui.check" - so each rule the file can give is read out of it here
| and looked up, one by one. A module's own format brings its own key, checked where it is used.
*/
it('has the words of every rule a box can break, in Arabic and in English', function () {
    $root = dirname(__DIR__, 2);
    $source = (string) file_get_contents($root.'/resources/js/lib/checks.ts');

    // Every problem the file writes - `{ rule: …` - a choice between two included.
    preg_match_all('/\{\s*rule:\s*([^,}]+)/', $source, $assignments);
    $rules = [];

    foreach ($assignments[1] as $expression) {
        preg_match_all("/'([a-z_]+)'/", $expression, $names);
        array_push($rules, ...$names[1]);
    }

    $rules = array_values(array_diff(array_unique($rules), ['format']));

    expect($rules)->toContain('required', 'number', 'range', 'max_length', 'phone')
        ->and(count($rules))->toBeGreaterThan(15);

    foreach (['ar', 'en'] as $locale) {
        $words = (array) ((require $root."/lang/{$locale}/ui.php")['check'] ?? []);

        foreach ($rules as $rule) {
            expect($words[$rule] ?? null)->toBeString("ui.check.{$rule} is missing in {$locale}")
                ->and((string) $words[$rule])->toContain(':field');
        }
    }
});
