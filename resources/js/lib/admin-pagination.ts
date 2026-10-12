type Links = {
    first_page_url?: string | null;
    prev_page_url?: string | null;
    next_page_url?: string | null;
};
/** Retain filters and the server's named page parameter (cards_page, orders_page, etc.). */
export function adminPageHref(source: Links, page: number, fallback?: string) {
    const reference =
        source.next_page_url ??
        source.prev_page_url ??
        source.first_page_url ??
        fallback ??
        window.location.href;
    const url = new URL(reference, window.location.origin);
    const first = source.first_page_url
        ? new URL(source.first_page_url, window.location.origin)
        : new URL(window.location.href);
    const keys = [...url.searchParams.keys()].filter(
        (key) => key === 'page' || key.endsWith('_page'),
    );
    const key =
        keys.find((key) => url.searchParams.get(key) !== first.searchParams.get(key)) ??
        keys[0] ??
        'page';
    url.searchParams.set(key, String(page));
    return url.pathname + url.search;
}
