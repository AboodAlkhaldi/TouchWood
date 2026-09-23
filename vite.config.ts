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
                bunny('IBM Plex Sans Arabic', { weights: [400, 500, 600, 700] }),
                bunny('IBM Plex Mono', { weights: [400, 500, 600] }),
            ],
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            // The same alias tsconfig.json declares, so an import reads the same to the editor, to
            // the type checker and to the bundler.
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
    },
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
