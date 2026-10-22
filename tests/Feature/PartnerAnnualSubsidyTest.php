<?php

use App\Application\Partners\PartnerAnnualSubsidies;
use App\Application\Partners\PartnerHierarchy;
use App\Application\Partners\PartnerListSummary;
use App\Application\Partners\PartnerManagement;
use App\Application\Partners\PartnerReport;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Tenant\PlatformOverviewQuery;
use App\Application\Tenant\PlatformOverviewSources;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('deducts signed own subsidies once across reports and summaries with immutable reversible evidence', function () {
    $this->seed();
    Http::preventStrayRequests();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $actor = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->firstOrFail();
    $management = app(PartnerManagement::class);
    $partner = $management->configure($actor, $tenant->id, ['account_id' => $user->account_id, 'enabled' => true, 'share_percent' => '40']);
    $baseline = app(PartnerReport::class)->read($tenant->id, $user->id, false);
    $ledger = DB::table('ledger_accounts')->orderBy('id')->get()->toJson();
    $input = fn ($amount) => ['kind' => 'ANNUAL_SUBSIDY', 'amount' => $amount, 'business_date' => '2026-10-21', 'note' => 'Offline annual subsidy fixture', 'request_id' => (string) Str::uuid(), 'confirmed' => true];
    $positive = $input('10.12345678');
    $entry = $management->journal($actor, $tenant->id, $partner->id, $positive);
    expect($management->journal($actor, $tenant->id, $partner->id, $positive)->id)->toBe($entry->id);
    $management->journal($actor, $tenant->id, $partner->id, $input('-2'));
    $report = app(PartnerReport::class)->read($tenant->id, $user->id, false);
    expect($report['annualSubsidy'])->toBe('8.12345678')
        ->and($report['stock'])->toBe((string) BigDecimal::of($baseline['stock'])->minus('8.12345678'))
        ->and($report['accountBalance'])->toBe($baseline['accountBalance'])
        ->and($report['share'])->toBe((string) BigDecimal::of($report['stock'])->multipliedBy('0.4')->toScale(8, RoundingMode::HalfUp));
    $summary = app(PartnerListSummary::class)->forRows([$partner])[$partner->id]['summary'];
    expect($summary['stock'])->toBe($report['stock'])->and($summary['annualSubsidy'])->toBe('8.12345678');
    $access = array_replace(array_fill_keys(['partners', 'inflow', 'outflow', 'cards', 'providers', 'assets'], false), ['partners' => true]);
    expect(app(PlatformOverviewQuery::class)->execute($access)['partners']['stock'])->toBe($report['stock'])
        ->and(app(PlatformOverviewSources::class)->read('stock', 'total', 1, null)['amount'])->toBe($report['stock']);
    // A descendant subsidy belongs only to that partner, never the ancestor.
    $membership = app(PromotionMembershipAction::class)->ensure($tenant->id, $user->id);
    $child = $user->replicate(['account_id']);
    $child->forceFill(['email' => 'subsidy-child@example.test'])->save();
    $child->refresh();
    app(PromotionMembershipAction::class)->ensure($tenant->id, $child->id, $membership->id);
    $childPartner = $management->configure($actor, $tenant->id, ['account_id' => $child->account_id, 'enabled' => true, 'share_percent' => '40']);
    $management->journal($actor, $tenant->id, $childPartner->id, $input('50'));
    $childReport = app(PartnerReport::class)->read($tenant->id, $child->id, false);
    $hierarchy = app(PartnerHierarchy::class)->read($tenant->id, null, $partner->id, 'children');
    expect($hierarchy['items'][0]['stock'])->toBe($childReport['stock'])
        ->and(app(PartnerReport::class)->read($tenant->id, $user->id, false)['stock'])->toBe($report['stock'])
        ->and(app(PlatformOverviewQuery::class)->execute($access)['partners']['stock'])->toBe($report['stock']);
    $other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    expect(app(PartnerAnnualSubsidies::class)->totals($other->id, [$partner->id]))->toBe([]);
    foreach (['0', '1e2', '1.123456789'] as $invalid) {
        expect(fn () => $management->journal($actor, $tenant->id, $partner->id, $input($invalid)))->toThrow(ValidationException::class);
    }
    $unconfirmed = $input('1');
    unset($unconfirmed['confirmed']);
    expect(fn () => $management->journal($actor, $tenant->id, $partner->id, $unconfirmed))->toThrow(ValidationException::class);
    $management->journal($actor, $tenant->id, $partner->id, $input('10.12345678') + ['reverses_id' => $entry->id]);
    expect(app(PartnerReport::class)->read($tenant->id, $user->id, false)['stock'])->toBe((string) BigDecimal::of($baseline['stock'])->plus('2'));
    expect(fn () => $management->journal($actor, $tenant->id, $partner->id, $input('10.12345678') + ['reverses_id' => $entry->id]))->toThrow(HttpException::class);
    expect(fn () => DB::transaction(fn () => DB::table('partner_journal_entries')->where('id', $entry->id)->update(['amount' => '1'])))->toThrow(QueryException::class);
    expect(DB::table('ledger_accounts')->orderBy('id')->get()->toJson())->toBe($ledger);
    Http::assertNothingSent();
});
