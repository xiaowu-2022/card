import { appDownloadUrl, downloadPlatform, type DownloadPlatform } from './app-download';
import { bootstrap, session } from './session';
import { t } from './i18n';

let opening = false;

export function openAppDownload() {
    // #ifdef H5
    const open = async (platform: DownloadPlatform) => {
        if (opening) return;
        opening = true;
        try {
            // A page can stay open while Platform changes the download destination.
            // Refresh before navigating; a failed read must not reuse the old URL.
            await bootstrap();
            const url = appDownloadUrl(session.value?.appDownloads, platform);
            if (url) window.location.assign(url);
            else uni.showToast({ title: t('App download is not available yet.'), icon: 'none' });
        } catch {
            uni.showToast({ title: t('Unable to load. Please try again.'), icon: 'none' });
        } finally {
            opening = false;
        }
    };
    const platform = downloadPlatform(
        navigator.userAgent,
        navigator.platform,
        navigator.maxTouchPoints,
    );
    if (platform) return open(platform);
    else
        uni.showActionSheet({
            itemList: [t('Download Android app'), t('Download iOS app')],
            success: ({ tapIndex }) => open(tapIndex === 0 ? 'android' : 'ios'),
        });
    // #endif
}
