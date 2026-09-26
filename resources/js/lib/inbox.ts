import { inboxTemplates } from './inbox-templates';
import { t } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';
export type InboxMessage = {
    id: string;
    kind: 'BUSINESS' | 'PLATFORM';
    template: string | null;
    parameters: { amount?: string; asset?: string };
    href: string | null;
    time: string;
    readAt: string | null;
    title: string | null;
    body: string | null;
};
export function messageCopy(message: InboxMessage) {
    if (message.kind === 'PLATFORM')
        return { title: message.title ?? '', body: message.body ?? '' };
    const parameters = {
        ...message.parameters,
        amount: message.parameters.amount ? exactAmount(message.parameters.amount) : '',
    };
    const [title, body] = inboxTemplates[message.template ?? ''] ?? [
        'Messages',
        'View related record',
    ];
    return { title: t(title), body: t(body, parameters) };
}
