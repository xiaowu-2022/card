const legacy=(function selectWebviewEntry(search) {
    const params = new URLSearchParams(search);
    return params.get('app_webview') === '1' && params.get('native_auth') !== 'token-v1';
})(location.search);
import(legacy?'./index-CjVTTRnC.js':"./index-CYgdrAtc.js");
