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

it('keeps the consumer navigation to home wallet cards and me only', function (): void {
    $navigation = file_get_contents(resource_path('js/components/user/user-navigation.ts'));

    expect($navigation)
        ->toContain("label: 'Home'", "label: 'Wallet'", "label: 'Cards'", "label: 'Me'")
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

it('does not expose internal KYC state or provider metadata in the user page', function (): void {
    $kyc = file_get_contents(resource_path('js/pages/user/Kyc.tsx'));

    expect($kyc)
        ->not->toContain('OCR Status', 'OCR Provider', 'confidence', 'Application UUID', "replaceAll('_', ' ')")
        ->toContain('Verification under review', 'Action required', 'Identity verified');
});

it('keeps user theme selectors out of admin layouts and pages', function (): void {
    $adminSources = collect([
        ...glob(resource_path('js/layouts/*AdminLayout.tsx')),
        ...glob(resource_path('js/pages/tenant-admin/*.tsx')),
        ...glob(resource_path('js/pages/platform/*.tsx')),
    ])->map(fn (string $path): string => file_get_contents($path))->implode("\n");

    expect($adminSources)->not->toContain('user-theme', '--user-primary', 'UserBalanceHero', 'UserBottomNavigation');
});
