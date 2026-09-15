import { useState } from 'react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { t, useAdminTranslation } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

// In-page calendar: does not invoke native date popups in embedded browsers.
export function AdminDateInput({
    id,
    label,
    value,
    onChange,
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
}) {
    const { i18n } = useAdminTranslation();
    const [open, setOpen] = useState(false);
    const [month, setMonth] = useState(new Date());
    const first = new Date(Date.UTC(month.getUTCFullYear(), month.getUTCMonth(), 1));
    const offset = (first.getUTCDay() + 6) % 7;
    const days = Array.from(
        { length: 42 },
        (_, index) =>
            new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth(), index - offset + 1)),
    );
    const monthLabel = new Intl.DateTimeFormat(i18n.language, {
        year: 'numeric',
        month: 'long',
        timeZone: 'UTC',
    }).format(first);
    const weekDay = new Intl.DateTimeFormat(i18n.language, { weekday: 'short', timeZone: 'UTC' });
    const moveMonth = (delta: number) =>
        setMonth(new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + delta, 1)));
    return (
        <div className="flex min-w-0 gap-2">
            <Input
                id={id}
                value={value}
                required
                maxLength={10}
                pattern="[0-9]{4}-[0-9]{2}-[0-9]{2}"
                placeholder={t('YYYY-MM-DD')}
                onChange={(event) => onChange(event.target.value)}
                onInput={(event) => onChange(event.currentTarget.value)}
            />
            <Dialog open={open} onOpenChange={setOpen}>
                <Button
                    type="button"
                    variant="secondary"
                    aria-label={t('Choose date: {{label}}', { label })}
                    onClick={() => {
                        const parsed = new Date(`${value}T12:00:00Z`);
                        setMonth(Number.isNaN(parsed.getTime()) ? new Date() : parsed);
                        setOpen(true);
                    }}
                >
                    <CalendarDays className="size-4" />
                </Button>
                <DialogContent
                    className="max-w-sm"
                    aria-describedby={undefined}
                    closeLabel={t('Close')}
                >
                    <DialogHeader>
                        <DialogTitle>{label}</DialogTitle>
                    </DialogHeader>
                    <div className="mb-4 flex items-center justify-between gap-2">
                        <Button
                            type="button"
                            variant="ghost"
                            aria-label={t('Previous month')}
                            onClick={() => moveMonth(-1)}
                        >
                            <ChevronLeft className="size-4" />
                        </Button>
                        <span className="font-semibold">{monthLabel}</span>
                        <Button
                            type="button"
                            variant="ghost"
                            aria-label={t('Next month')}
                            onClick={() => moveMonth(1)}
                        >
                            <ChevronRight className="size-4" />
                        </Button>
                    </div>
                    <div className="grid grid-cols-7 gap-1">
                        {days.slice(0, 7).map((day) => (
                            <span
                                key={day.toISOString()}
                                className="py-2 text-center text-xs text-muted-foreground"
                            >
                                {weekDay.format(day)}
                            </span>
                        ))}
                        {days.map((day) => {
                            const date = day.toISOString().slice(0, 10);
                            return (
                                <Button
                                    key={date}
                                    type="button"
                                    variant={date === value ? 'default' : 'ghost'}
                                    className={`h-10 min-w-0 px-0 ${day.getUTCMonth() === first.getUTCMonth() ? '' : 'text-muted-foreground'}`}
                                    aria-label={date}
                                    aria-pressed={date === value}
                                    onClick={() => {
                                        onChange(date);
                                        setOpen(false);
                                    }}
                                >
                                    {day.getUTCDate()}
                                </Button>
                            );
                        })}
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
