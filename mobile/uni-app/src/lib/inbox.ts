import { inboxTemplates } from '../generated/inbox-templates';
import { exactAmount } from '../generated/exact-amount';
import { t } from './i18n';
export type Message = { id: string; kind: 'BUSINESS' | 'PLATFORM'; template: string | null; parameters: { amount?: string; asset?: string }; title: string | null; body: string | null; href: string | null; time: string; readAt: string | null };
export function messageCopy(message: Message) {
    if (message.kind === 'PLATFORM') return { title: message.title ?? '', body: message.body ?? '' };
    const pair = inboxTemplates[message.template ?? ''] ?? ['Messages', 'View related record'];
    return { title: t(pair[0]), body: t(pair[1], { ...message.parameters, amount: message.parameters.amount ? exactAmount(message.parameters.amount) : '' }) };
}
