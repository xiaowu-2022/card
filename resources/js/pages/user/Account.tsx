import { Head, router, usePage } from '@inertiajs/react';
import { CircleHelp, Globe2, IdCard, LockKeyhole, LogOut, UserRound } from 'lucide-react';
import { UserListRow } from '@/components/user/UserListRow';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserSection } from '@/components/user/UserSection';
import { UserLayout } from '@/layouts/UserLayout';
import type { SharedProps } from '@/types/global';

function maskedContact(email: string | null | undefined, phone: string | null | undefined) {
    if (email) {
        const [local = '', domain = ''] = email.split('@');
        return `${local.slice(0, 1)}***@${domain}`;
    }
    if (phone) return `${phone.slice(0, 3)}••••${phone.slice(-3)}`;
    return 'No contact available';
}

export default function Account() {
    const { auth } = usePage<SharedProps>().props;
    const name = auth.user?.displayName?.trim() || 'Account holder';
    const initial = name.slice(0, 1).toUpperCase();
    return (
        <UserLayout>
            <Head title="Me" />
            <div className="space-y-7">
                <UserPageHeader title="Me" />
                <section className="flex items-center gap-4 px-1">
                    <div
                        className="grid size-14 shrink-0 place-items-center rounded-full bg-[var(--user-primary-soft)] text-lg font-semibold text-primary"
                        aria-hidden="true"
                    >
                        {initial}
                    </div>
                    <div className="min-w-0">
                        <h2 className="truncate text-lg font-semibold">{name}</h2>
                        <p className="mt-0.5 truncate text-sm text-muted-foreground">
                            {maskedContact(auth.user?.email, auth.user?.phone)}
                        </p>
                    </div>
                </section>
                <UserSection title="Account">
                    <div className="divide-y overflow-hidden rounded-[var(--user-radius-md)] border bg-surface">
                        <UserListRow
                            icon={UserRound}
                            title="Personal information"
                            description="Your verified account details"
                        />
                        <UserListRow icon={IdCard} title="Identity verification" href="/kyc" />
                        <UserListRow icon={LockKeyhole} title="Security" href="/account/security" />
                    </div>
                </UserSection>
                <UserSection title="Preferences">
                    <div className="overflow-hidden rounded-[var(--user-radius-md)] border bg-surface">
                        <UserListRow icon={Globe2} title="Language" value="English" />
                    </div>
                </UserSection>
                <UserSection title="Support">
                    <div className="overflow-hidden rounded-[var(--user-radius-md)] border bg-surface">
                        <UserListRow
                            icon={CircleHelp}
                            title="Help & support"
                            description="Contact your service provider"
                        />
                    </div>
                </UserSection>
                <div className="overflow-hidden rounded-[var(--user-radius-md)] border bg-surface">
                    <UserListRow
                        icon={LogOut}
                        title="Log out"
                        destructive
                        onClick={() => router.post('/logout')}
                    />
                </div>
            </div>
        </UserLayout>
    );
}
