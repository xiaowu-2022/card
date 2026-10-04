// Shared lock supports nested dialogs without unlocking the page underneath them.
let locks = 0;
let restore: (() => void) | null = null;
export function lockModalPage() {
    if (locks++ === 0) {
        const body = document.body;
        const { position, top, left, width, overflow } = body.style;
        const x = window.scrollX, y = window.scrollY;
        Object.assign(body.style, { position: 'fixed', top: `${-y}px`, left: `${-x}px`, width: '100%', overflow: 'hidden' });
        restore = () => {
            Object.assign(body.style, { position, top, left, width, overflow });
            window.scrollTo(x, y);
        };
    }
    let released = false;
    return () => {
        if (released) return;
        released = true;
        if (--locks === 0) { restore?.(); restore = null; }
    };
}
export function modalViewportStyle() {
    const viewport = window.visualViewport;
    return {
        '--modal-top': `${viewport?.offsetTop ?? 0}px`,
        '--modal-left': `${viewport?.offsetLeft ?? 0}px`,
        '--modal-height': `${viewport?.height ?? window.innerHeight}px`,
        '--modal-width': `${viewport?.width ?? window.innerWidth}px`,
    };
}
