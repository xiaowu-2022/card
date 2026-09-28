import { createSSRApp } from 'vue';
import { normalizeWebEntry } from './lib/entry';
import App from './App.vue';
export function createApp() {
    normalizeWebEntry();
    return { app: createSSRApp(App) };
}
