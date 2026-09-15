import { Aperture } from 'lucide-react';
import type { TenantSharedProps } from '@/types/global';

export function AuthBrand({ tenant }: { tenant: TenantSharedProps | null }) {
    const name = tenant?.branding.brandName.trim() || tenant?.name;

    if (!name) return null;

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
