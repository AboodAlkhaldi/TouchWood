import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            ssr: 'resources/js/ssr.tsx',
            refresh: true,
            // The design's fonts (frontend.md 1.8). The plugin downloads them at build time and
            // serves them from our own domain, which is the decision of 2026-09-19 - no page ever
            // asks a font service for anything.
            fonts: [
                // Its Arabic letters too: the plugin loads only the Latin subset unless told, and
                // every Arabic letter was drawn by the system's fallback face (found 2026-10-06).
                bunny('IBM Plex Sans Arabic', { weights: [400, 500, 600, 700], subsets: ['arabic', 'latin'] }),
                bunny('IBM Plex Mono', { weights: [400, 500, 600] }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        // The same aliases tsconfig.json declares, so an import reads the same to the editor, to
        // the type checker and to the bundler.
        alias: [
            { find: '@', replacement: fileURLToPath(new URL('./resources/js', import.meta.url)) },
            // shadcn's `cn`, told about Geist's type scale; only the bare name, so `cn/config`
            // inside it still reaches the package (resources/js/lib/cn.ts).
            { find: /^cn$/, replacement: fileURLToPath(new URL('./resources/js/lib/cn.ts', import.meta.url)) },
        ],
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
