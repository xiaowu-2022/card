// H5-only experiment: ask Android WebView to recalculate its viewport once.
// This changes the page viewport, not the native window or system-bar settings.
let attempted = false;

export function pulseLoginViewportOnce(): () => void {
    const noop = () => {};
    if (attempted || !/Android/i.test(navigator.userAgent)
        || !/(;\s*wv\b|Version\/4\.0|Html5Plus)/i.test(navigator.userAgent)) return noop;
    const meta = document.querySelector<HTMLMetaElement>('meta[name="viewport"]');
    if (!meta) return noop;
    let original = '', temporary = '', frame = 0;
    let restoreTimer: ReturnType<typeof setTimeout> | undefined;
    const restore = () => {
        clearTimeout(startTimer);
        clearTimeout(restoreTimer);
        cancelAnimationFrame(frame);
        // Do not overwrite a newer viewport setting from another component.
        if (temporary && meta.content === temporary) meta.content = original;
        document.removeEventListener('visibilitychange', restore);
        document.removeEventListener('focusin', restore);
    };
    const startTimer = setTimeout(() => {
        const focused = document.activeElement;
        if (document.hidden || focused?.matches('input, textarea, [contenteditable]')
            || window.scrollY !== 0 || Math.abs((window.visualViewport?.scale ?? 1) - 1) > 0.01) return;
        const height = window.visualViewport?.height ?? window.innerHeight;
        if (height < 200 || attempted) return;
        attempted = true;
        original = meta.content;
        // Expand the layout viewport by one CSS pixel and fit it to the screen.
        // Changing scale alone is clamped by width=device-width in Chromium.
        const width = Math.round(window.visualViewport?.width ?? window.innerWidth);
        const scale = (width / (width + 1)).toFixed(6);
        temporary = original.split(',').map(part => part.trim())
            .filter(part => !/^(initial-scale|width)\s*=/i.test(part))
            .concat(`width=${width + 1}`, `initial-scale=${scale}`).join(',');
        document.addEventListener('visibilitychange', restore);
        document.addEventListener('focusin', restore);
        meta.content = temporary;
        // A timer also restores it if the renderer suspends animation frames.
        restoreTimer = setTimeout(restore, 150);
        frame = requestAnimationFrame(() => { frame = requestAnimationFrame(restore); });
    }, 350);
    return restore;
}
