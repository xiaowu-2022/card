export const openEditorEvent = 'platform:open-editor';
export type OpenEditorDetail = { url: string; trigger: HTMLElement | null };

// Menu selections must finish their normal close/focus lifecycle before editing.
export function openAdminEditor(url: string, trigger: HTMLElement | null) {
    window.dispatchEvent(
        new CustomEvent<OpenEditorDetail>(openEditorEvent, { detail: { url, trigger } }),
    );
}
