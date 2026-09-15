<?php

it('keeps approved flows out of stale phase prohibitions', function (): void {
    $rules = file_get_contents(base_path('AGENTS.md'));
    expect($rules)->toContain('CURRENT_CAPABILITIES.md', 'never write Wallet/Ledger', 'Never manually modify wallet')
        ->not->toContain('the other requested management operations remain paused');
    expect(file_get_contents(base_path('docs/architecture/ARCHITECTURE.md')))
        ->not->toContain('The project stops after PhotonPay Cardholder setup');
    expect(file_get_contents(base_path('docs/architecture/UI_RULES.md')))
        ->not->toContain('is pending approval of the separately reported architecture conflict', 'Promotion is explicitly non-interactive');
    expect(file_get_contents(base_path('docs/architecture/CARD_PROVIDER_RULES.md')))
        ->not->toContain('Reveal is not implemented.');
});
