import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { local } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/css/filament/admin/theme.css'],
            refresh: true,
            /*
             * Archivo, as a true VARIABLE font, vendored rather than fetched.
             *
             * The weight is the string '100 900', not a number — that is the
             * variable RANGE, and it is what lets Tailwind's whole
             * `font-thin`..`font-black` scale resolve out of a single 35 KB
             * file. Nine static cuts would be nine downloads.
             *
             * Why the file is checked in rather than pulled from a provider:
             *
             *   - bunny/google's css2 API serves Archivo only as static
             *     per-weight cuts. `wght@100..900` is accepted and silently
             *     returns the same nine files, so there is no variable font
             *     to be had there.
             *   - the plugin's `fontsource` provider matches subset files by
             *     `-{subset}-{weight}-{style}`, which cannot match a variable
             *     file named `-latin-wght-normal` whose weight parses as
             *     "100 900". It throws.
             *
             * Only the `latin` subset is carried. Verified against the data,
             * not assumed: zero of 34,836 athlete names and none of the team
             * locations use a character outside Latin-1, so latin-ext would be
             * 32 KB that never renders a glyph.
             */
            fonts: [
                local('Archivo Variable', {
                    variants: [
                        {
                            src: 'resources/fonts/archivo-variable-latin.woff2',
                            weight: '100 900',
                            style: 'normal',
                        },
                    ],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
