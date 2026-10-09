import type { CardsPage } from './card-types';

// Only the masked page DTO, in memory for this sign-in. Never persist card
// secrets, action drafts, or authorization decisions in this preview.
let preview: { scope: string; page: CardsPage } | null = null;
const clone = (page: CardsPage): CardsPage => JSON.parse(JSON.stringify(page));

export function clearCardsPreview() {
    preview = null;
}
export function readCardsPreview(scope: string | null): CardsPage | null {
    if (!scope || preview?.scope !== scope) {
        clearCardsPreview();
        return null;
    }
    return clone(preview.page);
}
export function saveCardsPreview(scope: string, page: CardsPage) {
    preview = { scope, page: clone(page) };
}
