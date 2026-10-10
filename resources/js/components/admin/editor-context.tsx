import { showOperationResult } from './operation-result';
import { createContext, useContext, useEffect, useRef, useState } from 'react';
import { useForm as useInertiaForm, usePage as useInertiaPage } from '@inertiajs/react';
import { companyEditor } from './company-settings';
import { readEditorResponse } from './editor-response';
import type {
    FormDataType,
    FormDataErrors,
    Page,
    PageProps,
    UseFormSubmitOptions,
    UseFormTransformCallback,
} from '@inertiajs/core';

export type EditorContextValue = {
    page: Page;
    load: (url: string) => Promise<Page>;
    saved: (page?: Page, location?: string) => void;
    refresh: () => Promise<void>;
    navigate: (url: string) => void;
    canNavigate: () => boolean;
    error: (message: string) => void;
    state: (id: object, dirty: boolean, busy: boolean) => void;
};
export const EditorContext = createContext<EditorContextValue | null>(null);
export const useEditor = () => useContext(EditorContext);
export function usePage<T extends PageProps = PageProps>() {
    const page = useInertiaPage<T>();
    return (useEditor()?.page ?? page) as Page<T>;
}
function append(data: FormData, value: unknown, key: string) {
    if (value instanceof Blob) data.append(key, value);
    else if (Array.isArray(value)) value.forEach((v, i) => append(data, v, `${key}[${i}]`));
    else if (value && typeof value === 'object')
        Object.entries(value).forEach(([k, v]) => append(data, v, key ? `${key}[${k}]` : k));
    else
        data.append(
            key,
            value === true
                ? '1'
                : value === false
                  ? '0'
                  : typeof value === 'string'
                    ? value
                    : typeof value === 'number'
                      ? value.toString()
                      : '',
        );
}
export function useForm<T extends FormDataType<T>>(data: T | (() => T)) {
    const form = useInertiaForm<T>(data);
    const editor = useEditor();
    const [busy, setBusy] = useState(false);
    const inFlight = useRef(false);
    const id = useRef({});
    const transform = useRef<UseFormTransformCallback<T>>((value) => value);
    useEffect(() => {
        editor?.state(id.current, form.isDirty, busy);
        const key = id.current;
        return () => editor?.state(key, false, false);
    }, [editor, form.isDirty, busy]);
    const initial = typeof data === 'function' ? data() : data;
    const signature = JSON.stringify(initial);
    const baseline = useRef(signature);
    useEffect(() => {
        if (
            editor &&
            companyEditor(editor.page.url) &&
            !busy &&
            !form.isDirty &&
            baseline.current !== signature
        ) {
            baseline.current = signature;
            form.setDefaults(initial);
            form.setData(initial);
        }
    }, [editor, busy, form.isDirty, signature]);
    if (!editor) return form;
    const submit = async (method: string, url: string, options: UseFormSubmitOptions = {}) => {
        if (inFlight.current) return;
        inFlight.current = true;
        editor.state(id.current, form.isDirty, true);
        setBusy(true);
        form.clearErrors();
        editor.error('');
        try {
            const target = new URL(url, location.origin);
            if (target.origin !== location.origin || !target.pathname.startsWith('/platform/'))
                throw new Error('Invalid operation.');
            const body = new FormData();
            append(body, transform.current(form.data), '');
            if (method !== 'post') body.append('_method', method.toUpperCase());
            const response = await fetch(target, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Admin-Dialog': '1',
                    ...(companyEditor(editor.page.url)
                        ? { 'X-Admin-Company': companyEditor(editor.page.url)!.company }
                        : {}),
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie
                            .split('; ')
                            .find((v) => v.startsWith('XSRF-TOKEN='))
                            ?.slice(11) ?? '',
                    ),
                },
                body,
            });
            const result = (await readEditorResponse(response)) as {
                saved?: boolean;
                location?: string;
                errors?: Record<string, string | string[]>;
                error?: { message?: string };
                message?: string;
            };
            if (!response.ok) {
                const errors = Object.fromEntries(
                    Object.entries(
                        result.errors ?? {
                            form: result.error?.message ?? result.message ?? 'Unable to save.',
                        },
                    ).map(([key, value]) => [
                        key,
                        Array.isArray(value) ? (value[0] ?? 'Unable to save.') : value,
                    ]),
                );
                showOperationResult('error', Object.values(errors));
                form.setError(errors as FormDataErrors<T>);
                options.onError?.(errors);
                return;
            }
            if (result.saved !== true)
                throw new Error('Unexpected response. Please reload and try again.');
            if (result.location && editor.page.component === 'platform/TenantCreate') {
                form.setDefaults(form.data);
                editor.saved(undefined, result.location);
                return;
            }
            const refreshed = await editor.load(editor.page.url).catch(() => editor.page);
            form.setDefaults(form.data);
            options.onSuccess?.(refreshed);
            editor.state(id.current, false, true);
            editor.saved(refreshed);
        } catch (error) {
            editor.error(error instanceof Error ? error.message : 'Unable to save.');
            form.setError({
                form: error instanceof Error ? error.message : 'Unable to save.',
            } as Parameters<typeof form.setError>[0]);
        } finally {
            inFlight.current = false;
            setBusy(false);
            options.onFinish?.({} as never);
        }
    };
    return {
        ...form,
        processing: busy,
        transform: (callback: UseFormTransformCallback<T>) => {
            transform.current = callback;
        },
        post: (url: string, options?: UseFormSubmitOptions) => {
            void submit('post', url, options);
        },
        put: (url: string, options?: UseFormSubmitOptions) => {
            void submit('put', url, options);
        },
        patch: (url: string, options?: UseFormSubmitOptions) => {
            void submit('patch', url, options);
        },
        delete: (url: string, options?: UseFormSubmitOptions) => {
            void submit('delete', url, options);
        },
    };
}
