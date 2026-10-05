import { useEffect, useId, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { t } from '@/i18n/admin';

/** Manual activation keeps keyboard exploration from discarding an edited form. */
export function SettingsTabs({
    items,
    value,
    onChange,
    label,
    disabled = false,
}: {
    items: readonly { value: string; label: string }[];
    value: string;
    onChange: (value: string) => void;
    label: string;
    disabled?: boolean;
}) {
    const id = useId();
    const strip = useRef<HTMLDivElement>(null);
    const [edges, setEdges] = useState({ left: false, right: false });
    useEffect(() => {
        const node = strip.current;
        if (!node) return;
        const update = () =>
            setEdges({
                left: node.scrollLeft > 1,
                right: node.scrollLeft + node.clientWidth < node.scrollWidth - 1,
            });
        const observer = new ResizeObserver(() => {
            const selected = node.querySelector<HTMLElement>('[aria-selected="true"]');
            if (selected) revealTab(node, selected);
            update();
        });
        observer.observe(node);
        for (const child of node.children) observer.observe(child);
        node.addEventListener('scroll', update);
        update();
        return () => {
            observer.disconnect();
            node.removeEventListener('scroll', update);
        };
    }, [items]);
    useEffect(() => {
        const node = strip.current;
        const selected = node?.querySelector<HTMLElement>('[aria-selected="true"]');
        if (!node || !selected) return;
        // Only move this strip, never the page or the dialog body.
        revealTab(node, selected);
    }, [value, items]);
    return (
        <div className="flex min-w-0 items-center gap-1 border-b" data-settings-tabs>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={t('Previous')}
                aria-controls={id}
                disabled={!edges.left || disabled}
                onClick={() => strip.current?.scrollBy({ left: -240, behavior: 'smooth' })}
            >
                <ChevronLeft className="size-4" />
            </Button>
            <div
                id={id}
                ref={strip}
                role="tablist"
                aria-label={label}
                className="flex min-w-0 flex-1 overflow-x-auto overscroll-x-contain [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                onKeyDown={(event) => {
                    if (disabled || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key))
                        return;
                    const tabs = [
                        ...event.currentTarget.querySelectorAll<HTMLButtonElement>('[role="tab"]'),
                    ];
                    const index = tabs.indexOf(document.activeElement as HTMLButtonElement);
                    const next =
                        event.key === 'Home'
                            ? 0
                            : event.key === 'End'
                              ? tabs.length - 1
                              : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) %
                                tabs.length;
                    event.preventDefault();
                    tabs[next]?.focus({ preventScroll: true });
                    const target = tabs[next];
                    if (target && strip.current) revealTab(strip.current, target);
                }}
            >
                {items.map((item) => (
                    <button
                        key={item.value}
                        type="button"
                        role="tab"
                        aria-selected={item.value === value}
                        tabIndex={item.value === value ? 0 : -1}
                        disabled={disabled}
                        onClick={() => {
                            if (value !== item.value) onChange(item.value);
                        }}
                        className={cn(
                            'h-9 shrink-0 whitespace-nowrap border-b-2 px-4 text-sm font-medium outline-offset-[-3px] focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary disabled:opacity-50',
                            item.value === value
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:bg-muted hover:text-foreground',
                        )}
                    >
                        {t(item.label)}
                    </button>
                ))}
            </div>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                aria-label={t('Next')}
                aria-controls={id}
                disabled={!edges.right || disabled}
                onClick={() => strip.current?.scrollBy({ left: 240, behavior: 'smooth' })}
            >
                <ChevronRight className="size-4" />
            </Button>
        </div>
    );
}

function revealTab(strip: HTMLElement, tab: HTMLElement) {
    const item = tab.getBoundingClientRect();
    const viewport = strip.getBoundingClientRect();
    if (item.left < viewport.left) strip.scrollLeft -= viewport.left - item.left;
    else if (item.right > viewport.right) strip.scrollLeft += item.right - viewport.right;
}
