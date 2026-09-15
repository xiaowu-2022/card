import { useState, type ComponentProps } from 'react';
import { Input } from '@/components/ui/input';
import { displayMoney } from '@/lib/exact-amount';

// Formatting never emits a change: saving an unrelated setting preserves the
// original eight-decimal server value. Only user edits replace that value.
export function MoneyInput({
    value,
    onChange,
    onFocus,
    onBlur,
    ...props
}: Omit<ComponentProps<typeof Input>, 'value'> & { value: string }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState('');
    return (
        <Input
            {...props}
            inputMode="decimal"
            value={editing ? draft : displayMoney(value)}
            onFocus={(event) => {
                setDraft(displayMoney(value));
                setEditing(true);
                onFocus?.(event);
            }}
            onChange={(event) => {
                if (!/^\d*(?:\.\d{0,2})?$/.test(event.target.value)) return;
                setDraft(event.target.value);
                onChange?.(event);
            }}
            onBlur={(event) => {
                setEditing(false);
                onBlur?.(event);
            }}
        />
    );
}
