import { usePage } from '@inertiajs/react';
import { Check, Languages } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { configureClientLocale } from '@/i18n';
import { t, useAdminTranslation } from '@/i18n/admin';
import type { SharedProps } from '@/types/global';

export function AdminLanguageSwitcher() {
    const { i18n } = useAdminTranslation();
    const settings = usePage<SharedProps>().props.i18n;
    const [pending, setPending] = useState(false);
    const change = async (locale: string) => {
        if (pending || locale === i18n.language) return;
        setPending(true);
        try {
            const xsrf = document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.slice(11);
            const token = xsrf
                ? decodeURIComponent(xsrf)
                : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
            if (!token) throw new Error('CSRF token unavailable');
            const response = await fetch(
                settings.surface === 'platform' ? '/platform/locale' : '/admin/locale',
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        [xsrf ? 'X-XSRF-TOKEN' : 'X-CSRF-TOKEN']: token,
                    },
                    body: JSON.stringify({ locale }),
                },
            );
            if (!response.ok) throw new Error('Locale preference was not saved');
            // No reload, navigation, form remount, or business request replay.
            configureClientLocale(locale, settings.timezone);
        } catch {
            toast.error(t('Unable to change language. Please try again.'));
        } finally {
            setPending(false);
        }
    };
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    disabled={pending}
                    aria-label={t('Language')}
                >
                    <Languages className="size-4" aria-hidden="true" />
                    {i18n.language === 'en' ? 'English' : '简体中文'}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {(['zh-CN', 'en'] as const).map((locale) => (
                    <DropdownMenuItem
                        key={locale}
                        disabled={pending}
                        onSelect={() => void change(locale)}
                    >
                        <span lang={locale} className="flex-1">
                            {locale === 'en' ? 'English' : '简体中文'}
                        </span>
                        {locale === i18n.language && (
                            <Check className="ml-4 size-4" aria-hidden="true" />
                        )}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
