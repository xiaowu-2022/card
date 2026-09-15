import { useState } from 'react';
import { t } from '@/i18n/admin';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { displayMoney, exactAmount } from '@/lib/exact-amount';
import {
    chartDateLabelIndices,
    fundsChartScale,
    fundsLabels,
    type FundsDay,
    type FundsKey,
} from '@/lib/funds-chart';
const colors = { inflow: '#2563eb', outflow: '#d97706', net: '#0d9488' };

export function DailyFundsChart({
    days,
    series,
    bars = false,
}: {
    days: FundsDay[];
    series: FundsKey[];
    bars?: boolean;
}) {
    const [selected, setSelected] = useState(days.length - 1);
    const active = days[selected] ?? days[days.length - 1];
    const scale = fundsChartScale(days.flatMap((day) => series.map((key) => day[key] ?? '0')));
    const width = Math.max(760, days.length * 12);
    const left = 118;
    const right = width - 30;
    const top = 20;
    const bottom = 240;
    const step = (right - left) / Math.max(days.length, 1);
    const dateLabels = new Set(chartDateLabelIndices(days.length, right - left));
    const barWidth = Math.min(step * 0.6, 48);
    const x = (index: number) => left + step * (index + 0.5);
    const y = (amount: string) => top + scale.position(amount) * (bottom - top);
    const zero = y('0');
    const title = bars ? 'Daily retained funds' : 'Daily inflow and outflow';
    return (
        <Card className="min-w-0">
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <CardTitle>{t(title)}</CardTitle>
                <div className="flex flex-wrap gap-4 text-xs text-muted-foreground">
                    {series.map((key) => (
                        <span key={key} className="flex items-center gap-2">
                            <span
                                className="h-2 w-4 rounded-sm"
                                style={{ backgroundColor: colors[key] }}
                            />
                            {t(fundsLabels[key])}
                        </span>
                    ))}
                    <span>USDT</span>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="overflow-x-auto" tabIndex={0} role="region" aria-label={t(title)}>
                    <svg
                        viewBox={`0 0 ${width} 280`}
                        className="w-full"
                        style={{ minWidth: width }}
                        role="group"
                        aria-label={t(title)}
                    >
                        {scale.ticks.map((tick, index) => (
                            <g key={index}>
                                <line
                                    x1={left}
                                    x2={right}
                                    y1={y(tick)}
                                    y2={y(tick)}
                                    stroke="#e2e8f0"
                                    strokeDasharray="4 4"
                                />
                                <text
                                    x={left - 12}
                                    y={y(tick) + 4}
                                    textAnchor="end"
                                    className="fill-muted-foreground text-[11px]"
                                >
                                    {displayMoney(tick)}
                                </text>
                            </g>
                        ))}
                        <line x1={left} x2={right} y1={zero} y2={zero} stroke="#94a3b8" />
                        {!bars &&
                            series.map((key) => (
                                <g key={key}>
                                    <polyline
                                        fill="none"
                                        stroke={colors[key]}
                                        strokeWidth={2.5}
                                        strokeLinejoin="round"
                                        points={days
                                            .map(
                                                (day, index) => `${x(index)},${y(day[key] ?? '0')}`,
                                            )
                                            .join(' ')}
                                    />
                                    {days.map((day, index) => (
                                        <circle
                                            key={day.date}
                                            cx={x(index)}
                                            cy={y(day[key] ?? '0')}
                                            r={days.length > 60 ? 2 : 3}
                                            fill={colors[key]}
                                        />
                                    ))}
                                </g>
                            ))}
                        {bars &&
                            days.map((day, index) => {
                                const position = y(day.net ?? '0');
                                return (
                                    <rect
                                        key={day.date}
                                        x={x(index) - barWidth / 2}
                                        y={Math.min(zero, position)}
                                        width={barWidth}
                                        height={Math.abs(position - zero)}
                                        rx={2}
                                        fill={
                                            (day.net ?? '').startsWith('-') ? '#e11d48' : colors.net
                                        }
                                    />
                                );
                            })}
                        {days.map((day, index) => (
                            <g key={day.date}>
                                {dateLabels.has(index) && (
                                    <text
                                        x={x(index)}
                                        y={267}
                                        textAnchor="middle"
                                        className="fill-muted-foreground text-[11px]"
                                    >
                                        {day.date.slice(5)}
                                    </text>
                                )}
                                {active?.date === day.date && (
                                    <line
                                        x1={x(index)}
                                        x2={x(index)}
                                        y1={top}
                                        y2={bottom}
                                        stroke="#94a3b8"
                                        strokeDasharray="3 3"
                                    />
                                )}
                                <rect
                                    x={x(index) - step / 2}
                                    y={top}
                                    width={step}
                                    height={bottom - top}
                                    fill="transparent"
                                    tabIndex={0}
                                    role="button"
                                    className="cursor-crosshair outline-none focus:stroke-primary"
                                    aria-label={`${day.date}, ${series.map((key) => `${t(fundsLabels[key])} ${exactAmount(day[key] ?? '0')} USDT`).join(', ')}`}
                                    onMouseEnter={() => setSelected(index)}
                                    onFocus={() => setSelected(index)}
                                    onClick={() => setSelected(index)}
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter' || event.key === ' ') {
                                            event.preventDefault();
                                            setSelected(index);
                                        }
                                    }}
                                >
                                    <title>{`${day.date}\n${series.map((key) => `${t(fundsLabels[key])}: ${exactAmount(day[key] ?? '0')} USDT`).join('\n')}`}</title>
                                </rect>
                            </g>
                        ))}
                    </svg>
                </div>
                <div className="flex min-h-12 flex-wrap items-center gap-x-6 gap-y-2 rounded-lg bg-muted/60 px-4 py-3 text-sm">
                    <span className="font-medium tabular-nums">{active?.date}</span>
                    {series.map((key) => (
                        <span key={key} className="flex flex-wrap items-center gap-2">
                            <span className="text-muted-foreground">{t(fundsLabels[key])}</span>
                            <MoneyDisplay amount={active?.[key] ?? '0'} asset="USDT" />
                        </span>
                    ))}
                </div>
            </CardContent>
        </Card>
    );
}
