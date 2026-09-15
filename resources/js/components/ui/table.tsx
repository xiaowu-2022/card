import type {
    HTMLAttributes,
    TableHTMLAttributes,
    TdHTMLAttributes,
    ThHTMLAttributes,
} from 'react';
import { cn } from '@/lib/utils';

export function Table({ className, ...props }: TableHTMLAttributes<HTMLTableElement>) {
    return (
        <div className="relative block min-w-0 max-w-full overflow-x-auto">
            <table className={cn('w-full border-collapse text-sm', className)} {...props} />
        </div>
    );
}
export function TableHeader(props: HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className="border-b bg-muted/60" {...props} />;
}
export function TableBody(props: HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody {...props} />;
}
export function TableRow({ className, ...props }: HTMLAttributes<HTMLTableRowElement>) {
    return <tr className={cn('border-b last:border-0 hover:bg-muted/40', className)} {...props} />;
}
export function TableHead({ className, ...props }: ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th
            className={cn(
                'h-11 px-4 text-left text-xs font-semibold uppercase tracking-wide text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}
export function TableCell({ className, ...props }: TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('px-4 py-3.5', className)} {...props} />;
}
