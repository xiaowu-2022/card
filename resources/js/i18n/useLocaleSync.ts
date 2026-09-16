import { useLayoutEffect } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { configureClientLocale } from './index';
import { consumerLocaleScope, resolveConfirmedLocale } from './confirmed-locale';

export function useLocaleSync() {
    const { i18n, tenant, auth } = usePage<SharedProps>().props;
    const supplied = i18n?.locale ?? 'en';
    const locale =
        !i18n?.surface || i18n.surface === 'user'
            ? resolveConfirmedLocale(
                  consumerLocaleScope(tenant?.id, auth?.user?.id),
                  supplied,
                  i18n?.enabledLocales ?? ['en'],
              )
            : supplied;
    const timezone = i18n?.timezone ?? 'UTC';
    useLayoutEffect(() => {
        configureClientLocale(locale, timezone);
    }, [locale, timezone]);
}
