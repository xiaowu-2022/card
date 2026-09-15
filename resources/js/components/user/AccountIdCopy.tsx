import { useRef, useState } from 'react';
import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { t, useClientTranslation } from '@/i18n';

export function AccountIdCopy({ accountId }: { accountId: string }) {
    useClientTranslation();
    const identifier = useRef<HTMLElement>(null);
    const [status, setStatus] = useState<'ready' | 'copying' | 'copied' | 'failed'>('ready');

    async function copyAccountId() {
        setStatus('copying');
        try {
            await navigator.clipboard.writeText(accountId);
            setStatus('copied');
        } catch {
            setStatus('failed');
            if (identifier.current) {
                identifier.current.focus();
                const range = document.createRange();
                range.selectNodeContents(identifier.current);
                const selection = window.getSelection();
                selection?.removeAllRanges();
                selection?.addRange(range);
            }
        }
    }

    return (
        <div className="account-id-copy">
            <div className="account-id-row">
                <p id="account-id-label" className="text-sm text-muted-foreground">
                    {t('Account ID')}
                </p>
                <code
                    ref={identifier}
                    id="account-id"
                    className="block min-w-0 rounded-sm font-mono text-sm leading-6 break-all select-all"
                    aria-labelledby="account-id-label"
                    tabIndex={0}
                    dir="ltr"
                >
                    {accountId}
                </code>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-11 shrink-0"
                    aria-label={t('Copy account ID')}
                    title={t('Copy account ID')}
                    disabled={status === 'copying'}
                    onClick={() => void copyAccountId()}
                >
                    {status === 'copied' ? (
                        <Check className="size-4" aria-hidden="true" />
                    ) : (
                        <Copy className="size-4" aria-hidden="true" />
                    )}
                </Button>
            </div>
            <p
                role="status"
                className={status === 'failed' ? 'mt-2 text-xs text-muted-foreground' : 'sr-only'}
            >
                {status === 'copied'
                    ? t('Account ID copied.')
                    : status === 'failed'
                      ? t('Could not copy. Select the account ID and copy it manually.')
                      : ''}
            </p>
        </div>
    );
}
