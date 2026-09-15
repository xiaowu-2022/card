import { t, useClientTranslation } from '@/i18n';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import type { MoneyAmount } from '@/types/global';
import { Eye, EyeOff } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';

export function UserBalanceHero({
    amount,
    asset,
    assetLabel = '$',
}: {
    amount: MoneyAmount;
    asset: string;
    assetLabel?: string;
}) {
    useClientTranslation();
    const { auth } = usePage<SharedProps>().props;
    const key = `balance-hidden:${auth.user?.id ?? 'guest'}`;
    const hidden = useSyncExternalStore(
        (listener) => {
            window.addEventListener('balance-visibility', listener);
            return () => window.removeEventListener('balance-visibility', listener);
        },
        () => {
            try {
                return sessionStorage.getItem(key) === '1';
            } catch {
                return false;
            }
        },
        () => false,
    );
    const setHidden = (value: boolean) => {
        try {
            sessionStorage.setItem(key, value ? '1' : '0');
        } catch {
            /* Storage can be unavailable. */
        }
        window.dispatchEvent(new Event('balance-visibility'));
    };
    return (
        <section className="user-balance-hero" aria-labelledby="available-balance-title">
            <div className="user-balance-label">
                <p id="available-balance-title" className="font-normal">
                    {t('Available balance')}
                </p>
                <button
                    type="button"
                    onClick={() => setHidden(!hidden)}
                    aria-label={hidden ? t('Show balance') : t('Hide balance')}
                    aria-pressed={hidden}
                    className="user-balance-visibility"
                >
                    {hidden ? <EyeOff /> : <Eye />}
                </button>
            </div>
            <div
                className="user-balance-value"
                aria-label={hidden ? t('Balance hidden') : undefined}
            >
                {assetLabel === '$' && <span className="user-balance-asset">{assetLabel}</span>}
                <span className="user-balance-amount">
                    {hidden ? (
                        '••••••'
                    ) : (
                        <MoneyDisplay amount={amount} asset={asset} compact hideSymbol />
                    )}
                </span>
                {assetLabel !== '$' && <span className="user-balance-asset">{assetLabel}</span>}
            </div>
        </section>
    );
}
