import { appDownloadUrl, downloadPlatform, type DownloadPlatform } from './app-download';
import { session } from './session';
import { t } from './i18n';

export function openAppDownload() {
    // #ifdef H5
    const open = (platform: DownloadPlatform) => {
        const url = appDownloadUrl(session.value?.appDownloads, platform);
        if (url) window.location.assign(url);
        else uni.showToast({ title: t('App download is not available yet.'), icon: 'none' });
    };
    const platform = downloadPlatform(
        navigator.userAgent,
        navigator.platform,
        navigator.maxTouchPoints,
    );
    if (platform) open(platform);
    else
        uni.showActionSheet({
            itemList: [t('Download Android app'), t('Download iOS app')],
            success: ({ tapIndex }) => open(tapIndex === 0 ? 'android' : 'ios'),
        });
    // #endif
}
