export type Kind = 'ANNUAL' | 'ACTIVATION';
export type Selection = { kind: Kind; rank: number; page: number } | null;
const keys = ['invitation_partner', 'invitation_kind', 'invitation_rank', 'invitation_page'];
export function invitationLocation(partner: string | null, selection: Selection = null) {
    const url = new URL(location.href);
    keys.forEach((key) => url.searchParams.delete(key));
    if (partner) url.searchParams.set('invitation_partner', partner);
    if (selection) {
        url.searchParams.set('invitation_kind', selection.kind);
        url.searchParams.set('invitation_rank', String(selection.rank));
        url.searchParams.set('invitation_page', String(selection.page));
    }
    history.replaceState(history.state, '', url);
}
export function initialSelection(): Selection {
    const query = new URL(location.href).searchParams;
    const kind = query.get('invitation_kind');
    const rank = Number(query.get('invitation_rank')),
        page = Number(query.get('invitation_page') ?? 1);
    return (kind === 'ANNUAL' || kind === 'ACTIVATION') &&
        query.has('invitation_rank') &&
        Number.isInteger(rank) &&
        rank >= 0 &&
        Number.isInteger(page) &&
        page > 0
        ? { kind, rank, page }
        : null;
}
