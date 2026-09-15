import type { CSSProperties } from 'react';

const defaultPrimary = '#39AD8D';
const userBackground = '#F7F6F0';
const darkForeground = '#10231C';

function rgb(hex: string): [number, number, number] {
    const normalized = /^#[0-9a-f]{6}$/i.test(hex) ? hex : defaultPrimary;
    return [1, 3, 5].map((index) => Number.parseInt(normalized.slice(index, index + 2), 16)) as [
        number,
        number,
        number,
    ];
}

function luminance(color: [number, number, number]) {
    const channels = color.map((channel) => {
        const value = channel / 255;
        return value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * channels[0]! + 0.7152 * channels[1]! + 0.0722 * channels[2]!;
}

function contrast(first: [number, number, number], second: [number, number, number]) {
    const lighter = Math.max(luminance(first), luminance(second));
    const darker = Math.min(luminance(first), luminance(second));
    return (lighter + 0.05) / (darker + 0.05);
}

function readableBrandColor(primary: string) {
    const background = rgb(userBackground);
    let candidate = rgb(primary);
    while (contrast(candidate, background) < 4.5) {
        candidate = candidate.map((channel) => Math.round(channel * 0.88)) as [
            number,
            number,
            number,
        ];
    }
    return `#${candidate.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
}

export function userThemeStyle(primaryColor?: string | null): CSSProperties {
    const primary = /^#[0-9a-f]{6}$/i.test(primaryColor ?? '') ? primaryColor! : defaultPrimary;
    const primaryRgb = rgb(primary);
    const white = rgb('#FFFFFF');
    const dark = rgb(darkForeground);
    return {
        '--user-primary': primary,
        '--user-primary-readable': readableBrandColor(primary),
        '--tenant-primary': primary,
        '--tenant-primary-foreground':
            contrast(primaryRgb, white) >= contrast(primaryRgb, dark) ? '#FFFFFF' : darkForeground,
    } as CSSProperties;
}
