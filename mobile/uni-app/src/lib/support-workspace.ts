import { ApiError } from './api';
import { go } from './navigation';
import { t } from './i18n';
export type QuickReply = {
    id: string;
    title: string;
    body: string;
    revision: number;
    scope: 'shared' | 'personal';
};
export type ReplyPage = { data: QuickReply[]; current_page: number; last_page: number };
export function supportDenied(error: unknown): boolean {
    if (error instanceof ApiError && [401, 403, 404].includes(error.status)) {
        uni.showToast({ title: t('Support access is no longer available.'), icon: 'none' });
        go('/account', true);
        return true;
    }
    return false;
}
