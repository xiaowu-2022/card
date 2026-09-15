import { Badge } from '@/components/ui/badge';

export type StatusTone = 'SUCCESS' | 'WARNING' | 'DANGER' | 'INFO' | 'NEUTRAL';

const tones = {
    SUCCESS: 'success',
    WARNING: 'warning',
    DANGER: 'danger',
    INFO: 'info',
    NEUTRAL: 'neutral',
} as const;

export function StatusBadge({ status, label }: { status: StatusTone; label: string }) {
    return (
        <Badge tone={tones[status]} className="whitespace-nowrap">
            <span className="mr-1.5 size-1.5 rounded-full bg-current" aria-hidden="true" />
            {label}
        </Badge>
    );
}
