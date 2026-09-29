import { usePublicAsset } from '@/lib/public-assets';
import { t } from '@/i18n';

export function AcademyBenefitsPoster() {
    const publicAsset = usePublicAsset();
    return (
        <a
            href={publicAsset("/images/promotion/academy-level-benefits.png")}
            target="_blank"
            rel="noreferrer"
            className="mt-4 block rounded-lg focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--user-primary)]"
        >
            <img
                src={publicAsset("/images/promotion/academy-level-benefits.png")}
                alt={t('Promotion levels and membership benefits. Open the full-size image.')}
                width={1024}
                height={1536}
                className="h-auto w-full rounded-lg"
            />
        </a>
    );
}
