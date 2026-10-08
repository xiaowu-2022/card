export type DownloadPlatform = 'android' | 'ios';
export type AppDownloads = { androidDownloadUrl: string; iosDistributionUrl: string | null };

export function downloadPlatform(userAgent: string, platform = '', maxTouchPoints = 0): DownloadPlatform | null {
    if (/android/i.test(userAgent)) return 'android';
    if (/iPhone|iPad|iPod/i.test(userAgent) || (/Mac/i.test(platform) && maxTouchPoints > 1)) return 'ios';
    return null;
}

export function appDownloadUrl(downloads: AppDownloads | undefined, platform: DownloadPlatform): string | null {
    const url = platform === 'ios' ? downloads?.iosDistributionUrl : downloads?.androidDownloadUrl;
    if (typeof url !== 'string' || !/^https?:\/\/[^/@\s\\]+(?:[/?#][^\s\\]*)?$/i.test(url)) return null;
    return url;
}
