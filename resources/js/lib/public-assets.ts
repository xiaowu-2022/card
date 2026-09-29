import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export function usePublicAsset() {
    const { publicAssets } = usePage<{ publicAssets?: Record<string, string> }>().props;
    const [failed, setFailed] = useState<Record<string, boolean>>({});
    const requested = useRef(new Set<string>());
    const probed = useRef(new Set<string>());
    const probes = useRef<HTMLImageElement[]>([]);
    useEffect(() => () => { for (const image of probes.current) image.onerror = null; }, []);
    useEffect(() => {
        for (const url of requested.current) {
            if (probed.current.has(url)) continue;
            probed.current.add(url);
            const image = new Image();
            image.onerror = () => setFailed(old => ({ ...old, [url]: true }));
            image.src = url;
            probes.current.push(image);
        }
    });
    return (path: string) => {
        const remote = publicAssets?.[path];
        if (!remote || failed[remote]) return path;
        requested.current.add(remote);
        return remote;
    };
}
