import { useEffect, useRef } from 'react';
import { router as inertiaRouter } from '@inertiajs/react';
import { useEditor } from './editor-context';
import { companyEditor } from './company-settings';
import { readEditorResponse } from './editor-response';
import type { Page } from '@inertiajs/core';

/** Existing pages keep their ordinary Inertia transport outside an embedded editor. */
export function useEditorRouter(): typeof inertiaRouter {
    const editor = useEditor();
    const key = useRef({});
    const active = useRef(false);
    useEffect(() => () => editor?.state(key.current, false, false), [editor]);
    if (!editor) return inertiaRouter;
    const mutation = (
        method: string,
        url: string,
        data: object = {},
        options: {
            onSuccess?: (page: Page) => void;
            onError?: (errors: Record<string, string>) => void;
            onFinish?: () => void;
        } = {},
    ) => {
        if (active.current) return;
        active.current = true;
        editor.state(key.current, false, true);
        void (async () => {
            try {
                const target = new URL(url, location.origin);
                if (target.origin !== location.origin || !target.pathname.startsWith('/platform/'))
                    throw new Error('Invalid operation.');
                const company = companyEditor(editor.page.url)?.company;
                const response = await fetch(target, {
                    method,
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-Admin-Dialog': '1',
                        ...(company ? { 'X-Admin-Company': company } : {}),
                        'X-XSRF-TOKEN': decodeURIComponent(
                            document.cookie
                                .split('; ')
                                .find((v) => v.startsWith('XSRF-TOKEN='))
                                ?.slice(11) ?? '',
                        ),
                    },
                    body: JSON.stringify(data),
                });
                const result =
                    response.status === 204 ? { saved: true } : await readEditorResponse(response);
                if (!response.ok || result.saved !== true)
                    throw new Error(
                        typeof result.message === 'string' ? result.message : 'Unable to save.',
                    );
                const refreshed = await editor.load(editor.page.url);
                options.onSuccess?.(refreshed);
                editor.saved(refreshed);
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Unable to save.';
                editor.error(message);
                options.onError?.({ form: message });
            } finally {
                active.current = false;
                editor.state(key.current, false, false);
                options.onFinish?.();
            }
        })();
    };
    return {
        ...inertiaRouter,
        on: inertiaRouter.on.bind(inertiaRouter),
        get: (url: string, data: Record<string, unknown> = {}) => {
            const target = new URL(url, location.origin);
            for (const [key, value] of Object.entries(data)) {
                if (value === undefined || value === null || value === '')
                    target.searchParams.delete(key);
                else if (
                    typeof value === 'string' ||
                    typeof value === 'number' ||
                    typeof value === 'boolean'
                )
                    target.searchParams.set(key, String(value));
            }
            editor.navigate(target.pathname + target.search);
        },
        reload: () => {
            void editor
                .refresh()
                .catch((e: unknown) =>
                    editor.error(e instanceof Error ? e.message : 'Unable to load. Please retry.'),
                );
        },
        post: (url: string, data?: object, options?: Parameters<typeof mutation>[3]) =>
            mutation('POST', url, data, options),
        put: (url: string, data?: object, options?: Parameters<typeof mutation>[3]) =>
            mutation('PUT', url, data, options),
        delete: (url: string, options?: Parameters<typeof mutation>[3]) =>
            mutation('DELETE', url, {}, options),
    } as typeof inertiaRouter;
}

export function useEditorState(dirty: boolean, busy: boolean) {
    const editor = useEditor();
    const key = useRef({});
    useEffect(() => {
        const id = key.current;
        editor?.state(id, dirty, busy);
        return () => editor?.state(id, false, false);
    }, [editor, dirty, busy]);
}
