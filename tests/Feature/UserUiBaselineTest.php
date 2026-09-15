<?php

it('defines a scoped user theme without changing semantic status colors', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));

    expect($css)
        ->toContain('.user-theme', '--user-primary:', '--user-primary-readable:', '--user-primary-hover:', '--user-primary-soft:')
        ->toContain('--user-background:', '--user-surface:', '--user-surface-muted:')
        ->toContain('--user-text:', '--user-text-secondary:', '--user-border:')
        ->toContain('--user-radius-sm:', '--user-radius-md:', '--user-radius-lg:')
        ->toContain('--success: #067647', '--warning: #b54708', '--danger: #b42318');
});

it('keeps the live-reference consumer navigation to assets cards and me only', function (): void {
    $navigation = file_get_contents(resource_path('js/components/user/user-navigation.ts'));

    expect($navigation)
        ->toContain("label: 'Assets'", "label: 'Cards'", "label: 'Me'")
        ->not->toContain("label: 'Wallet'", "label: 'Home'")
        ->not->toContain("label: 'KYC'", "label: 'Verify'", "label: 'Transactions'", "label: 'Support'", "label: 'Settings'");
});

it('uses real wallet and Phase 10 card presentation without fake or sensitive content', function (): void {
    $dashboard = file_get_contents(resource_path('js/pages/user/Dashboard.tsx'));
    $wallet = file_get_contents(resource_path('js/pages/user/Wallet.tsx'));
    $cards = file_get_contents(resource_path('js/pages/user/Cards.tsx'));

    expect($dashboard.$wallet)
        ->toContain('UserBalanceHero')
        ->not->toContain('Mock Balance', 'Mock Transaction', '1,280.50', '12,840.25')
        ->and($wallet)
        ->not->toContain('USER_AVAILABLE', 'USER_SECURITY_DEPOSIT', 'ledger account', 'immutable account ledger')
        ->and($cards)
        ->toContain('Your cards', 'Open card')
        ->not->toContain('providerProductRef', 'CardBin', 'TEST / MOCK', 'VISA', '•••• 1234', 'Reveal', 'Freeze');
});

it('removes the dashboard promotion footer while preserving activity and wallet shortcuts', function (): void {
    $dashboard = file_get_contents(resource_path('js/pages/user/Dashboard.tsx'));

    expect($dashboard)->not->toContain('Promotion coming soon', 'Our promotion program is coming soon.', '<Gift')
        ->toContain('<UserWalletActions', '<UserActivityList', "t('Latest activity')");
});

it('removes the selected account placeholders and footer without removing real account controls', function (): void {
    $account = file_get_contents(resource_path('js/pages/user/Account.tsx'));

    expect($account)->not->toContain('user-profile-action', "t('Linked accounts')", "t('Coupons')", "t('Notifications')", "t('Online support')", "t('Community')", "t('Location data attribution')", 'tenant?.branding.brandName')
        ->toContain('maskedContact(auth.user?.email, auth.user?.phone)', "href: '/account/security'", '<AccountVerificationLink', 'href="/account/settings"')
        ->and(file_get_contents(resource_path('js/pages/user/AccountSettings.tsx')))->toContain('<LanguageSwitcher', "t('About us')", "router.post('/logout')");

    // Removing a footer link must not remove third-party license/attribution records.
    expect(is_file(public_path('data/card-geography/README.txt')))->toBeTrue();
});

it('does not expose internal KYC state or provider metadata in the user page', function (): void {
    $kyc = file_get_contents(resource_path('js/pages/user/Kyc.tsx'));

    expect($kyc)
        ->not->toContain('OCR Status', 'OCR Provider', 'confidence', 'Application UUID', "replaceAll('_', ' ')")
        ->toContain('Verification under review', 'Action required', 'Identity verified');
});

it('presents only virtual cards without a card type selector or physical card placeholder', function (): void {
    $cards = file_get_contents(resource_path('js/pages/user/Cards.tsx'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($cards)->toContain("t('Your cards')", "t('Apply for a card')")
        ->not->toContain('user-card-tabs', "t('Physical card')", "t('Card type')")
        ->and($css)->not->toContain('.user-card-tabs');
});

it('opens available products in a bounded dialog while retaining the existing confirmation and readiness gates', function (): void {
    $cards = file_get_contents(resource_path('js/pages/user/Cards.tsx'));
    $formOwner = substr($cards, strpos($cards, 'function ProductIssue('), strpos($cards, 'export default function Cards(') - strpos($cards, 'function ProductIssue('));

    expect($cards)->toContain('id="open-card-application"', "t('Apply for a card')", 'aria-haspopup="dialog"')
        ->toContain('props.kycApproved &&', 'props.providerAvailable &&', '!props.demo &&', '!unresolved &&', '!props.refundPending', 'open={applicationProductId === product.id}', 'key={product.id}')
        ->and($formOwner)->toContain('useForm({', '<Dialog', '<DialogContent', 'max-h-[calc(100dvh-2rem)]', 'overflow-y-auto')
        ->toContain('<AlertDialog', "t('Confirm card opening')", "form.post('/cards/issues'", '!materialsForm.processing && !reviewing')
        ->toContain("application?.state === 'ready'", 'cardholder_application_id: application?.id', '<CardholderMaterialsForm')
        ->toContain('onSelectProduct(product.id);')
        ->not->toContain("t('Your cardholder information is being reviewed.')", "t('Card setup submitted')", 'Materials for this card are approved.')
        ->not->toContain('props.profile', 'profile.legalFirstName')
        ->toContain("closeLabel={t('Close')}", 'errorMessage(formError)')
        ->and(strpos($formOwner, 'useForm({'))->toBeLessThan(strpos($formOwner, '<Dialog'));
});

it('keeps user theme selectors out of admin layouts and pages', function (): void {
    $adminSources = collect([
        ...glob(resource_path('js/layouts/*AdminLayout.tsx')),
        ...glob(resource_path('js/pages/tenant-admin/*.tsx')),
        ...glob(resource_path('js/pages/platform/*.tsx')),
    ])->map(fn (string $path): string => file_get_contents($path))->implode("\n");

    expect($adminSources)->not->toContain('user-theme', '--user-primary', 'UserBalanceHero', 'UserBottomNavigation');
});

it('preserves the measured client shell and truthful account shortcuts', function (): void {
    $css = file_get_contents(resource_path('css/app.css'));
    $account = file_get_contents(resource_path('js/pages/user/Account.tsx'));
    $actions = file_get_contents(resource_path('js/components/user/UserWalletActions.tsx'));

    expect($css)->toContain('max-width: 750px', '.user-menu-grid', '.user-bottom-navigation', '.user-auth-shell')
        ->and($account)->toContain("href: '/account/security'", '<AccountVerificationLink', 'href="/account/settings"')
        ->and($actions)->toContain("label: t('Security deposit')")
        ->not->toContain("href: '/promotion'", "href: '/exchange'", "href: '/scan'");
});

it('shows the authenticated account id without an avatar or promotion placeholders', function (): void {
    $account = file_get_contents(resource_path('js/pages/user/Account.tsx'));
    $copy = file_get_contents(resource_path('js/components/user/AccountIdCopy.tsx'));
    $actions = file_get_contents(resource_path('js/components/user/UserWalletActions.tsx'));
    $css = file_get_contents(resource_path('css/app.css'));

    expect($account)->toContain('accountId={auth.user.accountId}')
        ->not->toContain('user-profile-avatar', "t('Invite friends')", "t('Rewards')")
        ->and($copy)->toContain('navigator.clipboard.writeText(accountId)', '<code', "setStatus('failed')", 'range.selectNodeContents(identifier.current)', 'role="status"')
        ->and($actions)->not->toContain("t('Promotion')", 'Gift', "href: '/wallet/transfer'")
        ->and($css)->not->toContain('.user-profile-avatar');
});
