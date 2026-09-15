import * as Popover from '@radix-ui/react-popover';
import { Command } from 'cmdk';
import { Check, ChevronDown, Search } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

export type SearchOption = { value: string; label: string; keywords?: string[] };

// shadcn's Radix Popover + Command composition. Only existing options can be selected.
export function SearchSelect({
    id,
    label,
    value,
    options,
    placeholder,
    searchLabel,
    emptyLabel,
    disabled,
    invalid,
    onValueChange,
    compact = false,
}: {
    id: string;
    label: string;
    value: string;
    options: SearchOption[];
    placeholder: string;
    searchLabel: string;
    emptyLabel: string;
    disabled?: boolean;
    invalid?: boolean;
    compact?: boolean;
    onValueChange: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const selected = options.find((option) => option.value === value);
    return (
        <Popover.Root open={open} onOpenChange={setOpen} modal>
            <Popover.Trigger asChild>
                <button
                    id={id}
                    type="button"
                    role="combobox"
                    aria-label={label}
                    aria-expanded={open}
                    aria-invalid={invalid || undefined}
                    aria-describedby={invalid ? `${id}-error` : undefined}
                    disabled={disabled}
                    className={cn(
                        'flex min-h-11 w-full min-w-0 items-center justify-between gap-2 rounded-lg border bg-white px-3 py-2 text-left text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-50',
                        !value && 'text-muted-foreground',
                        invalid && 'border-danger',
                    )}
                >
                    <span className="truncate">
                        {selected
                            ? compact
                                ? selected.label.split(' ')[0]
                                : selected.label
                            : placeholder}
                    </span>
                    <ChevronDown className="size-4 shrink-0 opacity-50" />
                </button>
            </Popover.Trigger>
            <Popover.Portal>
                <Popover.Content
                    align="start"
                    sideOffset={5}
                    collisionPadding={12}
                    aria-label={label}
                    className="z-[60] w-[var(--radix-popover-trigger-width)] min-w-[240px] max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border bg-white text-foreground shadow-xl"
                >
                    <Command label={label}>
                        <div className="flex items-center gap-2 border-b px-3">
                            <Search className="size-4 shrink-0 text-muted-foreground" />
                            <Command.Input
                                aria-label={searchLabel}
                                placeholder={searchLabel}
                                className="h-11 w-full min-w-0 bg-transparent text-sm outline-none"
                            />
                        </div>
                        <Command.List
                            label={label}
                            className="overflow-y-auto overscroll-contain p-1"
                            style={{
                                maxHeight:
                                    'min(280px, calc(var(--radix-popover-content-available-height) - 48px))',
                            }}
                        >
                            <Command.Empty className="px-3 py-6 text-center text-sm text-muted-foreground">
                                {emptyLabel}
                            </Command.Empty>
                            {options.map((option) => (
                                <Command.Item
                                    key={option.value}
                                    value={option.value}
                                    keywords={[option.label, ...(option.keywords ?? [])]}
                                    onSelect={() => {
                                        onValueChange(option.value);
                                        setOpen(false);
                                    }}
                                    className="flex min-h-10 cursor-pointer items-center justify-between gap-2 rounded-md px-3 py-2 text-sm data-[selected=true]:bg-muted"
                                >
                                    {option.label}
                                    <Check
                                        className={cn(
                                            'size-4 shrink-0',
                                            value !== option.value && 'invisible',
                                        )}
                                    />
                                </Command.Item>
                            ))}
                        </Command.List>
                    </Command>
                </Popover.Content>
            </Popover.Portal>
        </Popover.Root>
    );
}
