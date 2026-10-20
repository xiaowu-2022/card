import type { Identity } from './token-session';
export function sessionEntry(identity: Pick<Identity, 'signedIn'> & Partial<Pick<Identity, 'restricted'>>, guest: string) {
    return !identity.signedIn ? guest : identity.restricted
        ? '/#/pages/screen/index?path=%2Faccount%2Frestricted' : '/#/pages/assets/index';
}
// Load public H5 assets while native identity/mailbox requests are in flight.
// commit is the only point that may enable credential injection or reveal H5.
export async function startSessionDocument(ports: {
    expectedSignedIn: boolean;
    guestEntry: string;
    preload(entry: string): void;
    restore(): Promise<Identity>;
    current(): boolean;
    commit(identity: Identity, redirect: string | null): void;
}) {
    const initial = sessionEntry({ signedIn: ports.expectedSignedIn }, ports.guestEntry);
    ports.preload(initial);
    const identity = await ports.restore();
    if (!ports.current()) return;
    const final = sessionEntry(identity, ports.guestEntry);
    ports.commit(identity, final === initial ? null : final);
}
