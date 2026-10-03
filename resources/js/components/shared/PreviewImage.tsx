import { useState, type ImgHTMLAttributes } from 'react';
import { t } from '@/i18n/admin';

type Props = ImgHTMLAttributes<HTMLImageElement> & { sources?: string[] };
export function PreviewImage({ sources = [], src, ...props }: Props) {
    const candidates = [
        ...new Set([src, ...sources].filter((value): value is string => Boolean(value))),
    ];
    return <ImageAttempt key={JSON.stringify(candidates)} candidates={candidates} {...props} />;
}
function ImageAttempt({
    candidates,
    ...props
}: Omit<Props, 'src' | 'sources'> & { candidates: string[] }) {
    const [index, setIndex] = useState(0);
    if (index >= candidates.length)
        return (
            <span
                role="button"
                tabIndex={0}
                className="cursor-pointer text-sm text-muted-foreground"
                onClick={(event) => {
                    event.stopPropagation();
                    setIndex(0);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        event.stopPropagation();
                        setIndex(0);
                    }
                }}
            >
                {t('Image failed to load. Click to retry.')}
            </span>
        );
    return (
        <img
            {...props}
            key={candidates[index]}
            src={candidates[index]}
            onError={() => setIndex((value) => (value === index ? value + 1 : value))}
        />
    );
}
