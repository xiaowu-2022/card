// Transparent native windows can suspend animation frames. Recheck the same
// document and layout before reporting content, whichever scheduler runs first.
export function readinessScript(token: string): string {
    return `(function () {
        if (/^specpay-(ready|debug)-/.test(document.title)) return;
        var root = document.querySelector('uni-page-body');
        function valid() {
            if (!root || document.querySelector('uni-page-body') !== root
                || root.querySelector('[data-webview-pending="true"]')
                || window.innerWidth < 100 || window.innerHeight < 100) return false;
            var rect = root.getBoundingClientRect(), style = window.getComputedStyle(root);
            return rect.width >= 100 && rect.height >= 40 && !!root.innerText.trim()
                && style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
        }
        if (!valid()) return;
        var route = window.location.href, rect = root.getBoundingClientRect();
        var width = rect.width, height = rect.height, finished = false;
        function report() {
            if (finished) return;
            finished = true;
            window.clearTimeout(fallback);
            if (window.location.href !== route || !valid()) return;
            var current = root.getBoundingClientRect();
            if (current.width !== width || current.height !== height) return;
            var original = document.title;
            var token = ${JSON.stringify(token)};
            document.title = token;
            window.setTimeout(function () { if (document.title === token) document.title = original; }, 100);
        }
        var fallback = window.setTimeout(report, 200);
        window.requestAnimationFrame(function () { window.requestAnimationFrame(report); });
    })();`;
}
