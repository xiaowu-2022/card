import { usePage } from '@inertiajs/react';

export function usePublicAsset() {
    const { publicAssets } = usePage<{ publicAssets?: Record<string, string> }>().props;
    return (path: string) => publicAssets?.[path] ?? path;
}
