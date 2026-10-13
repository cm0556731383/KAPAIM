import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
    build: {
        // crm_vite rebuilds on every Blade/CSS save (`build --watch`). Emptying
        // public/build first leaves the live site with no manifest for the
        // length of the build, and every page 500s until it finishes.
        emptyOutDir: false,
    },
});
