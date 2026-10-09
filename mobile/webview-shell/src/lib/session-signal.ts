// No cookie/token/user details pass through document.title. The remote page
// receives no plus/native privileges, including when diagnostics are enabled.
export function sessionSignalScript(prefix: string): string {
    return `(function () {
        if (window.__consumerSessionListener) window.removeEventListener('consumer-session-state', window.__consumerSessionListener);
        var prefix = ${JSON.stringify(prefix)};
        var restoreTitle = /^specpay-(ready|debug|session|poster)-/.test(document.title) ? 'Spec Pay' : document.title;
        function emit(state) {
            if (!state || typeof state.signedIn !== 'boolean' || typeof state.tenantId !== 'string') return;
            if (!/^specpay-(ready|debug|session|poster)-/.test(document.title) && document.title.indexOf(prefix) !== 0) restoreTitle = document.title;
            window.__consumerSessionSequence = (window.__consumerSessionSequence || 0) + 1;
            var title = prefix + JSON.stringify({signedIn:state.signedIn,tenantId:state.tenantId,sequence:window.__consumerSessionSequence});
            document.title = title;
            window.setTimeout(function () { if (document.title === title) document.title = restoreTitle; }, 100);
        }
        window.__consumerSessionListener = function (event) { emit(event.detail); };
        window.addEventListener('consumer-session-state', window.__consumerSessionListener);
        emit(window.__consumerSessionState);
    })();`;
}
