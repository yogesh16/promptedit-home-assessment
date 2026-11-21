import vue from '@vitejs/plugin-vue';
import autoprefixer from 'autoprefixer';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from 'tailwindcss';
import { defineConfig, loadEnv } from 'vite';

export default defineConfig(({ mode }) => {
    const env = { ...loadEnv(mode, process.cwd(), ''), ...process.env };
    const appUrl = new URL(env.APP_URL ?? 'http://localhost:8080');
    const devServerOrigin =
        env.VITE_DEV_SERVER_ORIGIN || `${appUrl.protocol}//${appUrl.hostname}:5175`;

    return {
        plugins: [
            laravel({
                input: ['resources/js/app.ts'],
                refresh: true,
            }),
            vue({
                template: {
                    transformAssetUrls: {
                        base: null,
                        includeAbsolute: false,
                    },
                },
            }),
        ],
        resolve: {
            alias: {
                '@': path.resolve(__dirname, './resources/js'),
            },
        },
        server: {
            host: '0.0.0.0',
            port: 5175,
            strictPort: true,
            cors: true,
            origin: devServerOrigin,
            hmr: {
                host: new URL(devServerOrigin).hostname,
            },
        },
        css: {
            postcss: {
                plugins: [tailwindcss, autoprefixer],
            },
        },
    };
});
