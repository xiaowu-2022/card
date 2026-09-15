export const tenantArticles = [
    { key: 'terms', title: 'Terms of service' },
    { key: 'privacy', title: 'Privacy policy' },
    { key: 'account-closure', title: 'Account closure' },
] as const;

export type TenantArticleKey = (typeof tenantArticles)[number]['key'];
export type TenantArticleContent = { key: TenantArticleKey; locale: string; body: string };
