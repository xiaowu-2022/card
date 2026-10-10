import { useEffect, useRef, useSyncExternalStore } from 'react';
import { router } from '@inertiajs/react';
import * as AlertDialog from '@radix-ui/react-alert-dialog';
import { Button } from '@/components/ui/button';
import { t, useAdminTranslation } from '@/i18n/admin';
import {
    acknowledgeOperationResult,
    getOperationResults,
    isPlatform,
    showOperationResult,
    subscribeOperationResults,
} from './operation-result';

export function OperationResultHost() {
    useAdminTranslation();
    const returnFocus = useRef<HTMLElement | null>(null);
    const confirmButton = useRef<HTMLButtonElement | null>(null);
    const result = useSyncExternalStore(subscribeOperationResults, getOperationResults)[0];
    useEffect(() => {
        const mutations = new Set<string>();
        const off = [
            router.on('start', (event) => {
                if (isPlatform() && event.detail.visit.method !== 'get')
                    mutations.add(event.detail.visit.id);
            }),
            router.on('success', (event) => {
                if (!isPlatform()) return;
                const flash = event.detail.page.props.flash as
                    { success?: string; error?: string } | undefined;
                if (flash?.error) showOperationResult('error', flash.error);
                else if (flash?.success) showOperationResult('success', flash.success);
                else if (event.detail.visitId && mutations.has(event.detail.visitId))
                    showOperationResult('success', 'Request completed.');
            }),
            router.on('error', (event) => {
                if (isPlatform())
                    showOperationResult('error', Object.values(event.detail.errors).flat());
            }),
            router.on('finish', (event) => {
                mutations.delete(event.detail.visit.id);
            }),
            router.on('networkError', (event) => {
                if (!isPlatform()) return;
                event.preventDefault();
                showOperationResult('error', 'Network request failed. Please try again.');
            }),
            router.on('httpException', (event) => {
                if (!isPlatform()) return;
                event.preventDefault();
                showOperationResult('error', 'Unable to complete this request.');
            }),
        ];
        return () => off.forEach((remove) => remove());
    }, []);
    return (
        <AlertDialog.Root open={!!result}>
            <AlertDialog.Portal>
                <AlertDialog.Overlay className="fixed inset-0 z-[100] bg-slate-950/50" />
                <AlertDialog.Content
                    data-platform-ui
                    data-operation-result
                    className="fixed left-1/2 top-1/2 z-[101] flex max-h-[calc(100dvh-2rem)] w-[calc(100%-2rem)] max-w-lg -translate-x-1/2 -translate-y-1/2 flex-col gap-4 rounded-xl border bg-surface p-5 shadow-xl"
                    onOpenAutoFocus={(event) => {
                        returnFocus.current =
                            document.activeElement instanceof HTMLElement
                                ? document.activeElement
                                : null;
                        event.preventDefault();
                        confirmButton.current?.focus();
                    }}
                    onCloseAutoFocus={(event) => {
                        if (returnFocus.current?.isConnected) {
                            event.preventDefault();
                            returnFocus.current.focus({ preventScroll: true });
                        }
                    }}
                    onEscapeKeyDown={(event) => event.preventDefault()}
                >
                    <AlertDialog.Title className="text-lg font-semibold">
                        {t(result?.kind === 'error' ? 'Operation failed' : 'Operation result')}
                    </AlertDialog.Title>
                    <AlertDialog.Description className="min-h-0 overflow-y-auto whitespace-pre-wrap break-words text-sm">
                        {result?.messages.join('\n')}
                    </AlertDialog.Description>
                    <div className="flex shrink-0 justify-end">
                        <AlertDialog.Action asChild>
                            <Button
                                className="h-9 min-h-9 shrink-0 whitespace-nowrap"
                                ref={confirmButton}
                                onClick={acknowledgeOperationResult}
                            >
                                {t('OK')}
                            </Button>
                        </AlertDialog.Action>
                    </div>
                </AlertDialog.Content>
            </AlertDialog.Portal>
        </AlertDialog.Root>
    );
}
