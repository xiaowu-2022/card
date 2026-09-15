import { usePage } from '@inertiajs/react';
import { Globe2, Check, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { configureClientLocale, t, useClientTranslation } from '@/i18n';
import type { SharedProps } from '@/types/global';

const names: Record<string, string> = {
    en: 'English',
    'zh-CN': '简体中文',
    ms: 'Bahasa Melayu',
    es: 'Español',
};

export function LanguageSwitcher({
    variant = 'pill',
}: {
    variant?: 'pill' | 'menu' | 'icon' | 'row';
}) {
    const { i18n } = useClientTranslation();
    const { i18n: suppliedSettings } = usePage<SharedProps>().props;
    const settings = suppliedSettings ?? { enabledLocales: ['en'], timezone: 'UTC' };
    const [pending, setPending] = useState(false);
    const change = async (locale: string) => {
        if (pending || locale === i18n.language) return;
        setPending(true);
        try {
            const xsrf = document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.slice('XSRF-TOKEN='.length);
            const token = xsrf
                ? decodeURIComponent(xsrf)
                : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
            if (!token) throw new Error('CSRF token unavailable');
            const response = await fetch('/locale', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    [xsrf ? 'X-XSRF-TOKEN' : 'X-CSRF-TOKEN']: token,
                },
                body: JSON.stringify({ locale }),
            });
            if (!response.ok) throw new Error('Locale preference was not saved');
            // No navigation/remount: preserve form data and stable financial request IDs.
            configureClientLocale(locale, settings.timezone);
        } catch {
            toast.error(t('Language could not be changed. Please try again.'));
        } finally {
            setPending(false);
        }
    };
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    disabled={pending}
                    aria-label={t('Language')}
                    className={
                        variant === 'row'
                            ? 'user-settings-row w-full text-left'
                            : variant === 'menu'
                              ? 'user-menu-item w-full'
                              : variant === 'icon'
                                ? 'user-header-action'
                                : 'inline-flex min-h-11 items-center gap-2 rounded-full border border-black/10 px-4 py-2 text-sm sm:text-xl'
                    }
                >
                    {variant === 'row' ? (
                        <>
                            <Globe2
                                className="size-5 shrink-0 text-[var(--user-primary)]"
                                aria-hidden="true"
                            />
                            <span className="min-w-0 flex-1">{t('Language')}</span>
                            <span className="max-w-[45%] text-right text-sm text-muted-foreground">
                                {names[i18n.language]}
                            </span>
                            <ChevronRight
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden="true"
                            />
                        </>
                    ) : variant === 'menu' ? (
                        <>
                            <span className="user-menu-icon">
                                <Globe2 aria-hidden="true" />
                            </span>
                            <span className="user-menu-label">{t('Language')}</span>
                            <span className="user-menu-note">{names[i18n.language]}</span>
                        </>
                    ) : variant === 'icon' ? (
                        <Globe2 strokeWidth={2.2} aria-hidden="true" />
                    ) : (
                        <>
                            <Globe2 className="size-4" aria-hidden="true" />
                            {names[i18n.language]}
                        </>
                    )}
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-48">
                {settings.enabledLocales
                    .filter((locale) => names[locale])
                    .map((locale) => (
                        <DropdownMenuItem
                            key={locale}
                            disabled={pending}
                            onSelect={() => void change(locale)}
                        >
                            <span lang={locale} className="flex-1">
                                {names[locale]}
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
