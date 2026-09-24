import '../css/app.css';
import { createInertiaApp } from '@inertiajs/react';
import { createRoot } from 'react-dom/client';
import { Toaster } from '@/components/ui/toast';
import type { ComponentType } from 'react';
import { configureClientLocale } from '@/i18n';

const pages = import.meta.glob<ComponentType>('./pages/**/*.tsx', {
    import: 'default',
});

void createInertiaApp({
    title: (title) => (title ? `${title} · Aperture Cards` : 'Aperture Cards'),
    resolve: (name) => {
        const page = pages[`./pages/${name}.tsx`];
        if (!page) throw new Error(`Inertia page not found: ${name}`);
        return page();
    },
    setup({ el, App, props }) {
        const locale = props.initialPage.props.i18n as
            { locale: string; timezone: string } | undefined;
        configureClientLocale(locale?.locale ?? 'en', locale?.timezone ?? 'UTC');
        createRoot(el).render(
            <>
                <App {...props} />
                <Toaster />
            </>,
        );
    },
    progress: { color: '#155EEF' },
});
