// A fixed bottom:0 follows the layout viewport. Embedded WebViews can expose
// a smaller visible viewport after system bars or the keyboard change size.
export function navigationViewportBottom(): number {
    const viewport = window.visualViewport;
    // Let pinch zoom pan naturally rather than dragging navigation over content.
    if (!viewport || Math.abs(viewport.scale - 1) > 0.01 || viewport.height <= 0) return 0;
    return Math.max(0, window.innerHeight - viewport.offsetTop - viewport.height);
}

export function syncNavigationViewport() {
    document.documentElement.style.setProperty('--shell-viewport-bottom', `${navigationViewportBottom()}px`);
}
