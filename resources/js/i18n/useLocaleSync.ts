import { useLayoutEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { configureClientLocale } from './index';

export function useLocaleSync() {
    const { i18n } = usePage<SharedProps>().props;
    const locale = i18n?.locale ?? 'en';
    const timezone = i18n?.timezone ?? 'UTC';
    useLayoutEffect(() => {
        configureClientLocale(locale, timezone);
    }, [locale, timezone]);
}
