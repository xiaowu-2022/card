import { defineConfig } from 'vite';
import uni from '@dcloudio/vite-plugin-uni';
import config from './src/generated/company.json';
export default defineConfig({
    define: { 'import.meta.env.UNI_PLATFORM': JSON.stringify(process.env.UNI_PLATFORM) },
    plugins: [
        uni(),
        {
            name: 'screen-import-retry',
            augmentChunkHash() {
                return 'screen-import-retry-v1';
            },
            generateBundle: {
                order: 'post',
                handler(_, bundle) {
                    if (process.env.UNI_PLATFORM !== 'h5') return;
                    // Run after Vite collects CSS/preload dependencies. Only explicit recovery
                    // bypasses WebKit's failed-module cache; ordinary hashed URLs stay cacheable.
                    for (const chunk of Object.values(bundle)) {
                        if (chunk.type !== 'chunk') continue;
                        chunk.code = chunk.code.replace(
                            /import\((['"])(\.\/screens-[^'"]+\.js)\1\)/g,
                            (_, quote, path) =>
                                `import(${quote}${path}${quote} + (/^\\d+$/.test(new URLSearchParams(location.search).get('_screen_retry') || '') ? '?retry=' + new URLSearchParams(location.search).get('_screen_retry') : ''))`,
                        );
                    }
                },
            },
        },
    ],
    build: { sourcemap: false },
    server: {
        host: '127.0.0.1',
        port: 5200,
        strictPort: true,
        proxy: {
            '/api/v1': { target: config.apiOrigin, changeOrigin: true },
            '/storage': { target: config.apiOrigin, changeOrigin: true },
        },
    },
});
