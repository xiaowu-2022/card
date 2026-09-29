import { defineConfig } from 'vite';
import uni from '@dcloudio/vite-plugin-uni';
import config from './src/generated/company.json';
export default defineConfig({
    define: { 'import.meta.env.UNI_PLATFORM': JSON.stringify(process.env.UNI_PLATFORM) },
    plugins: [uni()], build: { sourcemap: false },
    server: { host: '127.0.0.1', port: 5200, strictPort: true, proxy: {
        '/api/v1': { target: config.apiOrigin, changeOrigin: true },
        '/storage': { target: config.apiOrigin, changeOrigin: true },
    } },
});
