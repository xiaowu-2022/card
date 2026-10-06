import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ComponentType,
    type ReactNode,
} from 'react';
import { router, usePage } from '@inertiajs/react';
import type { Page } from '@inertiajs/core';
import { openEditorEvent, type OpenEditorDetail } from './editor-navigation';
import { EditorContext, useEditor } from './editor-context';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { t, errorMessage } from '@/i18n/admin';
import { readEditorResponse } from './editor-response';
import { SettingsTabs } from './SettingsTabs';
import {
    companyEditor,
    companySettings,
    companySettingsUrl,
    companySectionEvent,
} from './company-settings';
import type { SharedProps } from '@/types/global';

const pages = import.meta.glob<ComponentType<Record<string, unknown>>>('../../pages/**/*.tsx', {
    import: 'default',
});
function editorPath(url: URL): boolean {
    return (
        url.origin === location.origin &&
        ((url.pathname === '/platform/settings/assets' && !!url.searchParams.get('company')) ||
            /^\/platform\/tenants\/create$/.test(url.pathname) ||
            /^\/platform\/tenants\/[^/]+\/users\/[^/]+\/(wallet-adjustments|referrer|invitation-code|promotion|manual-commissions)$/.test(
                url.pathname,
            ) ||
            /^\/platform\/tenants\/[^/]+\/configuration\/(settings(\/(branding|locales|business|articles|sms|email))?|promotion|paid-promotion|wealth)$/.test(
                url.pathname,
            ))
    );
}
export function AdminEditorHost({ children }: { children: ReactNode }) {
    const embedded = useEditor();
    return embedded ? <>{children}</> : <EditorHost>{children}</EditorHost>;
}
function EditorHost({ children }: { children: ReactNode }) {
    const version = usePage().version;
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const backgroundCompanies = usePage<{ companies?: { id: string; name: string }[] }>().props
        .companies;
    const [url, setUrl] = useState(() => new URL(location.href).searchParams.get('editor'));
    const [page, setPage] = useState<Page | null>(null);
    const [Component, setComponent] = useState<ComponentType<Record<string, unknown>> | null>(null);
    const [error, setError] = useState('');
    const [operationError, setOperationError] = useState('');
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(false);
    const [retry, setRetry] = useState(0);
    const bodyRef = useRef<HTMLDivElement>(null);
    const [actions, setActions] = useState<
        { node: HTMLButtonElement; label: string; disabled: boolean }[]
    >([]);
    const states = useRef(new Map<object, { dirty: boolean; busy: boolean }>());
    const trigger = useRef<HTMLElement | null>(null);
    const generation = useRef(0);
    const state = useCallback((id: object, dirty: boolean, active: boolean) => {
        if (!dirty && !active) states.current.delete(id);
        else states.current.set(id, { dirty, busy: active });
        setBusy([...states.current.values()].some((entry) => entry.busy));
    }, []);
    const canClose = useCallback(
        () =>
            ![...states.current.values()].some((entry) => entry.busy) &&
            (![...states.current.values()].some((entry) => entry.dirty) ||
                confirm(t('Discard unsaved changes?'))),
        [],
    );
    const changeUrl = useCallback((next: string | null) => {
        const target = new URL(location.href);
        if (next) target.searchParams.set('editor', next);
        else target.searchParams.delete('editor');
        const config = companyEditor(next);
        if (config && target.pathname === '/platform/company-configurations')
            target.searchParams.set('section', config.section);
        history.replaceState(history.state, '', target);
        window.dispatchEvent(new Event(companySectionEvent));
        setUrl(next);
    }, []);
    const close = useCallback(() => {
        if (canClose()) changeUrl(null);
    }, [canClose, changeUrl]);
    const load = useCallback(
        async (target: string): Promise<Page> => {
            const parsed = new URL(target, location.origin);
            if (!editorPath(parsed)) throw new Error(t('This operation is unavailable.'));
            const response = await fetch(parsed, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'X-Inertia': 'true',
                    'X-Inertia-Version': version ?? '',
                    'X-Admin-Dialog': '1',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const result = (await readEditorResponse(response)) as Partial<Page> & {
                error?: { message?: string };
                message?: string;
            };
            if (!response.ok || !result.component || !result.props)
                throw new Error(
                    result.error?.message ?? result.message ?? t('Unable to load. Please retry.'),
                );
            const config = companyEditor(target);
            if (config) {
                const owner = result.props.configurationCompany as { id?: string } | undefined;
                const company = result.props.company as string | { id?: string } | undefined;
                const ids = [owner?.id, typeof company === 'string' ? company : company?.id].filter(
                    Boolean,
                );
                const base = result.props.configurationBase;
                if (
                    !ids.length ||
                    ids.some((id) => id !== config.company) ||
                    (base && base !== `/platform/tenants/${config.company}/configuration`)
                )
                    throw new Error(t('This operation is unavailable.'));
            }
            return result as Page;
        },
        [version],
    );
    useEffect(() => {
        states.current.clear();
        setBusy(false);
        setPage(null);
        setComponent(null);
        setError('');
        setOperationError('');
        const ticket = ++generation.current;
        if (!url) return;
        setLoading(true);
        void load(url)
            .then(async (result) => {
                const resolve = pages[`../../pages/${result.component}.tsx`];
                if (!resolve) throw new Error(t('This operation is unavailable.'));
                const component = await resolve();
                if (ticket === generation.current) {
                    changeUrl(url);
                    setPage(result);
                    setComponent(() => component);
                }
            })
            .catch((failure: Error) => {
                if (ticket === generation.current) setError(failure.message);
            })
            .finally(() => {
                if (ticket === generation.current) setLoading(false);
            });
        return () => {
            generation.current++;
        };
    }, [url, load, retry, changeUrl]);
    useEffect(() => {
        const click = (event: MouseEvent) => {
            if (
                event.button !== 0 ||
                event.metaKey ||
                event.ctrlKey ||
                event.shiftKey ||
                event.altKey
            )
                return;
            const link = (event.target as HTMLElement).closest<HTMLAnchorElement>('a[href]');
            if (!link || link.target === '_blank') return;
            const target = new URL(link.href, location.origin);
            if (!editorPath(target)) {
                if (
                    url &&
                    link.closest('[data-admin-editor-body]') &&
                    ['/platform/users', '/platform/tenants'].includes(target.pathname)
                ) {
                    event.preventDefault();
                    event.stopPropagation();
                    close();
                }
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            if (!canClose()) return;
            if (!url) trigger.current = link;
            changeUrl(target.pathname + target.search);
        };
        const openEditor = (event: Event) => {
            const detail = (event as CustomEvent<OpenEditorDetail>).detail;
            const target = new URL(detail.url, location.origin);
            if (!editorPath(target) || !canClose()) return;
            if (!url) trigger.current = detail.trigger;
            changeUrl(target.pathname + target.search);
        };
        const pop = () => {
            const next = new URL(location.href).searchParams.get('editor');
            if (next === url) return;
            if (canClose()) setUrl(next);
            else changeUrl(url);
        };
        const before = (event: BeforeUnloadEvent) => {
            if ([...states.current.values()].some((entry) => entry.dirty || entry.busy)) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        const removeGuard = router.on('before', (event) => {
            if (url && !canClose()) event.preventDefault();
        });
        document.addEventListener('click', click, true);
        window.addEventListener(openEditorEvent, openEditor);
        window.addEventListener('popstate', pop);
        window.addEventListener('beforeunload', before);
        return () => {
            removeGuard();
            document.removeEventListener('click', click, true);
            window.removeEventListener(openEditorEvent, openEditor);
            window.removeEventListener('popstate', pop);
            window.removeEventListener('beforeunload', before);
        };
    }, [url, changeUrl, canClose, close]);
    useEffect(() => {
        const body = bodyRef.current;
        if (!body || !Component) {
            setActions([]);
            return;
        }
        const sync = () => {
            const buttons = [
                ...body.querySelectorAll<HTMLButtonElement>(
                    'form button[type="submit"], form button:not([type])',
                ),
            ];
            const next = buttons
                .filter(
                    (node) =>
                        node.closest('form') && !node.closest('[role="dialog"] [role="dialog"]'),
                )
                .map((node) => ({
                    node,
                    label: node.textContent?.trim() || t('Save'),
                    disabled: node.matches(':disabled'),
                }));
            for (const action of next) action.node.dataset.editorAction = 'true';
            setActions((old) =>
                old.length === next.length &&
                old.every(
                    (a, i) =>
                        a.node === next[i]?.node &&
                        a.label === next[i]?.label &&
                        a.disabled === next[i]?.disabled,
                )
                    ? old
                    : next,
            );
        };
        sync();
        const observer = new MutationObserver(sync);
        observer.observe(body, {
            subtree: true,
            childList: true,
            characterData: true,
            attributes: true,
            attributeFilter: ['disabled'],
        });
        return () => observer.disconnect();
    }, [Component, url]);
    const saved = useCallback(() => {
        states.current.clear();
        changeUrl(null);
        router.reload();
    }, [changeUrl]);
    const context = useMemo(
        () =>
            page
                ? {
                      page,
                      load,
                      saved,
                      state,
                      error: setOperationError,
                      navigate: (next: string) => {
                          if (canClose()) changeUrl(next);
                      },
                  }
                : null,
        [page, load, saved, state, canClose, changeUrl],
    );
    const account = page?.props.account as
        { companyName?: string; accountId?: string; email?: string } | undefined;
    const titles: Record<string, string> = {
        'platform/WalletAdjustment': 'Wallet adjustment',
        'platform/UserInvitationCode': 'Change invitation code',
        'platform/UserReferrer': 'Change referrer',
        'platform/UserPromotion': 'Adjust promotion level',
        'platform/ManualCommission': 'Adjust commission',
        'platform/TenantCreate': 'Create tenant',
    };
    const config = companyEditor(url);
    const companyName =
        readCompanyName(page?.props.configurationCompany) ??
        (page?.props.companies as { id: string; name: string }[] | undefined)?.find(
            (c) => c.id === config?.company,
        )?.name ??
        readCompanyName(page?.props.company) ??
        backgroundCompanies?.find((c) => c.id === config?.company)?.name;

    return (
        <>
            {children}
            <Dialog
                open={!!url}
                onOpenChange={(open) => {
                    if (!open) close();
                }}
            >
                <DialogContent
                    className="flex max-w-6xl flex-col overflow-hidden p-0"
                    closeLabel={t('Close')}
                    closeDisabled={busy}
                    onCloseAutoFocus={(event) => {
                        event.preventDefault();
                        trigger.current?.focus();
                    }}
                >
                    <div className="shrink-0 border-b px-4 py-4 pr-16">
                        <DialogTitle>
                            {companyName
                                ? `${companyName} · ${t('Company configuration')}`
                                : t(titles[page?.component ?? ''] ?? 'Edit record')}
                        </DialogTitle>
                        <DialogDescription>
                            {account
                                ? [account.companyName, account.accountId, account.email]
                                      .filter(Boolean)
                                      .join(' · ')
                                : t('Changes apply only to the record shown here.')}
                        </DialogDescription>
                    </div>
                    {config && permissions.includes('tenant.manage') && (
                        <div className="min-w-0 shrink-0 px-4">
                            <SettingsTabs
                                label={t('Company configuration')}
                                items={companySettings}
                                value={config.section}
                                disabled={busy}
                                onChange={(section) => {
                                    if (canClose())
                                        changeUrl(companySettingsUrl(config.company, section));
                                }}
                            />
                        </div>
                    )}
                    <div
                        ref={bodyRef}
                        data-admin-editor-body="true"
                        className="min-h-0 overflow-y-auto overscroll-contain p-4 [&_button[data-editor-action]]:hidden"
                        scroll-region="true"
                    >
                        {operationError && (
                            <p
                                role="alert"
                                className="mb-4 rounded border border-destructive p-3 text-destructive"
                            >
                                {errorMessage(operationError)}
                            </p>
                        )}
                        {loading && <p role="status">{t('Loading…')}</p>}
                        {error && (
                            <div role="alert">
                                <p>{error}</p>
                                <Button
                                    onClick={() => {
                                        setRetry((value) => value + 1);
                                    }}
                                >
                                    {t('Retry')}
                                </Button>
                                <Button variant="secondary" onClick={() => location.reload()}>
                                    {t('Refresh page')}
                                </Button>
                            </div>
                        )}
                        {context && Component && (
                            <EditorContext.Provider value={context}>
                                <Component {...page!.props} />
                            </EditorContext.Provider>
                        )}
                    </div>
                    <div className="flex shrink-0 flex-wrap justify-end gap-2 border-t bg-surface px-4 py-3">
                        {actions.map((action, index) => (
                            <Button
                                key={index}
                                disabled={busy || action.disabled}
                                onClick={() => action.node.click()}
                            >
                                {action.label}
                            </Button>
                        ))}
                        <Button variant="secondary" disabled={busy} onClick={close}>
                            {t('Close')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

function readCompanyName(value: unknown): string | undefined {
    return value && typeof value === 'object' && 'name' in value && typeof value.name === 'string'
        ? value.name
        : undefined;
}
