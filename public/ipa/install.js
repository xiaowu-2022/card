'use strict';
const agent = navigator.userAgent;
if (/MicroMessenger|QQ\//i.test(agent)) document.getElementById('browser-tip').hidden = false;
if (location.protocol !== 'https:') document.getElementById('https-tip').hidden = false;
for (const link of document.querySelectorAll('.install')) {
    link.addEventListener('click', event => {
        const status = document.getElementById('install-status');
        if (location.protocol !== 'https:') {
            event.preventDefault();
            status.textContent = '请先使用 HTTPS 地址打开本页，再尝试安装。';
        } else if (!/iPhone|iPad|iPod/.test(agent) && !(navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) {
            event.preventDefault();
            status.textContent = '请在 iPhone 或 iPad 上用 Safari 打开本页安装；电脑可选择下载 IPA 文件。';
        } else if (/MicroMessenger|QQ\//i.test(agent)) {
            event.preventDefault();
            status.textContent = '请通过右上角菜单在 Safari 中打开本页后安装。';
        } else {
            status.textContent = '已请求系统打开安装清单，请确认安装提示。随后返回主屏幕查看进度；若没有出现提示，请检查网络及安装包签名。';
        }
        status.hidden = false;
    });
}
