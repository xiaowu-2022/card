<?php

use App\Application\Assets\AssetActivityLabel;

it('describes financial phases without claiming a hold is a completed withdrawal', function (string $event, string $amount, string $label) {
    expect(AssetActivityLabel::for($event, $amount))->toBe($label);
})->with([
    ['ASSET_WITHDRAWAL_HOLD', '-10', 'Withdrawal requested'],
    ['ASSET_WITHDRAWAL_RELEASE', '10', 'Withdrawal returned'],
    ['CARD_ISSUE_FEE_HOLD', '-2', 'Card opening fee reserved'],
    ['CARD_LOAD_RELEASE', '20', 'Card reload returned'],
    ['WEALTH_INTEREST', '15', 'Wealth interest received'],
    ['WEALTH_CANCEL', '980', 'Wealth early withdrawal returned'],
    ['WEALTH_MATURITY', '1000', 'Wealth maturity principal returned'],
    ['WALLET_TRANSFER', '-20', 'Transfer sent'],
    ['WALLET_TRANSFER', '20', 'Transfer received'],
    ['UNRECOGNIZED', '20', 'Other wallet activity'],
]);
