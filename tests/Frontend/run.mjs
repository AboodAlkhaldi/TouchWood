/*
| Runs the screens' own unit tests: every `*.test.ts` under tests/Frontend, written with Node's
| built-in runner (node:test) - no test framework is installed for them.
|
| Node 20 cannot read TypeScript, so each file is first bundled for Node by Vite, the project's own
| bundler, with the same `@` alias as vite.config.ts, into a folder of its own that is removed
| afterwards. Then `node --test` runs the bundles and its report (TAP) is passed through, with its
| exit code. tests/Architecture/FrontendUnitTest.php runs this inside the PHP suite, so
| `composer check` and CI run it too.
*/

import { spawnSync } from 'node:child_process';
import { mkdtempSync, readdirSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';

const here = fileURLToPath(new URL('.', import.meta.url));
const root = resolve(here, '../..');
const files = readdirSync(here, { recursive: true })
    .map(String)
    .filter((name) => name.endsWith('.test.ts'))
    .map((name) => join(here, name))
    .sort();

if (files.length === 0) {
    console.error('No *.test.ts file under tests/Frontend.');
    process.exit(1);
}

const out = mkdtempSync(join(tmpdir(), 'tw-frontend-'));

try {
    for (const file of files) {
        await build({
            configFile: false,
            logLevel: 'silent',
            root,
            resolve: {
                alias: [
                    { find: '@', replacement: join(root, 'resources/js') },
                    { find: /^cn$/, replacement: join(root, 'resources/js/lib/cn.ts') },
                ],
            },
            build: {
                ssr: file,
                outDir: out,
                emptyOutDir: false,
                minify: false,
                rolldownOptions: { output: { entryFileNames: `${basename(file, '.ts')}.mjs` } },
            },
        });
    }

    const bundles = files.map((file) => join(out, `${basename(file, '.ts')}.mjs`));
    const run = spawnSync(process.execPath, ['--test', '--test-reporter=tap', ...bundles], { stdio: 'inherit' });

    process.exitCode = run.status ?? 1;
} finally {
    rmSync(out, { recursive: true, force: true });
}
