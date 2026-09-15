import { Aperture } from 'lucide-react';
import { useState } from 'react';
import { t } from '@/i18n';
import type { TenantSharedProps } from '@/types/global';

export function AuthBrand({
    tenant,
    promotional = false,
}: {
    tenant: TenantSharedProps | null;
    promotional?: boolean;
}) {
    const name = tenant?.branding.brandName.trim() || tenant?.name;
    const logoUrl = tenant?.branding.logoUrl;
    const [failedLogoUrl, setFailedLogoUrl] = useState<string | null>(null);

    if (!name) return null;

    if (promotional) {
        return (
            <div className="user-auth-promotion">
                <div className="user-auth-promotion-copy">
                    {logoUrl && logoUrl !== failedLogoUrl ? (
                        <img
                            src={logoUrl}
                            alt={name}
                            className="user-auth-promotion-logo"
                            onError={() => setFailedLogoUrl(logoUrl)}
                        />
                    ) : (
                        <p className="user-auth-promotion-name">{name}</p>
                    )}
                </div>
                <img
                    src="/images/marketing/spec-pay-gold-world.png"
                    alt={t('Mastercard U Card')}
                    width={1586}
                    height={992}
                    className="user-auth-promotion-card"
                    fetchPriority="high"
                    decoding="async"
                />
            </div>
        );
    }

    return (
        <div className="user-auth-brand">
            {tenant?.branding.logoUrl ? (
                <img src={tenant.branding.logoUrl} alt="" className="user-auth-logo" />
            ) : (
                <Aperture className="user-auth-logo" aria-hidden="true" />
            )}
            <p className="user-auth-company">{name}</p>
        </div>
    );
}
