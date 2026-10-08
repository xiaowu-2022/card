// Keep the injected probe compatible with older Android WebViews. It only
// checks the H5 app's visible layout; it never reads form values or credentials.
export function readinessScript(token: string): string {
    return `(function () {
        if (/^specpay-(ready|debug)-/.test(document.title)) return;
        var root = document.querySelector('uni-page-body');
        if (!root || window.innerWidth < 100 || window.innerHeight < 100) return;
        var rect = root.getBoundingClientRect();
        if (rect.width < 100 || rect.height < 40 || !root.innerText.trim()) return;
        var style = window.getComputedStyle(root);
        if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') return;
        window.requestAnimationFrame(function () { window.requestAnimationFrame(function () {
            var original = document.title;
            var token = ${JSON.stringify(token)};
            document.title = token;
            window.setTimeout(function () { if (document.title === token) document.title = original; }, 100);
        }); });
    })();`;
}
