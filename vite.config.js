import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ command }) => ({
    // Production build: files live under /build on whatever host serves the app.
    // Without this, laravel-vite-plugin bakes ASSET_URL from .env into the compiled CSS and
    // lazy-loaded chunks (fonts, charts), so a build made with ASSET_URL=http://localhost
    // would fetch them from localhost on a real server.
    base: command === 'build' ? '/build/' : undefined,
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js'],
            refresh: true,
        }),
    ],
}));
