import { t } from '@/i18n';

export const wealthState = (state: string) =>
    t(
        state === 'AWAITING_SETTLEMENT'
            ? 'Matured awaiting settlement'
            : state === 'ACTIVE'
              ? 'Earning interest'
              : state === 'MATURED'
                ? 'Matured and returned'
                : 'Cancelled early',
    );
