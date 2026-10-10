<?php

use App\Application\Partners\PartnerManagement;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('filters current enabled partners and keeps company search and pagination scope read only', function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $active = User::where('tenant_id', $tenant->id)->firstOrFail();
    $disabled = $active->replicate(['account_id']);
    $disabled->forceFill(['email' => 'disabled-partner@example.test'])->save();
    $disabled->refresh();
    $ordinary = $active->replicate(['account_id']);
    $ordinary->forceFill(['email' => 'ordinary-partner-filter@example.test'])->save();
    $ordinary->refresh();
    $other = User::where('tenant_id', '<>', $tenant->id)->firstOrFail();
    foreach ([[$active, true], [$disabled, false], [$other, true]] as [$user, $enabled]) {
        app(PartnerManagement::class)->configure($owner, $user->tenant_id, ['account_id' => $user->account_id, 'enabled' => $enabled, 'share_percent' => '40']);
    }
    $before = DB::table('ledger_accounts')->orderBy('id')->get()->toJson();
    $partners = DB::table('partner_configurations')->orderBy('id')->get()->toJson();
    $this->actingAs($owner, 'platform_admin');
    $url = 'http://admin.localhost/platform/users';
    $this->get($url.'?company='.$tenant->id.'&partner=Enabled')->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 1)->where('users.data.0.id', $active->id)->where('filters.partner', 'Enabled'));
    $this->get($url.'?partner=Enabled')->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 2));
    foreach ([$disabled, $ordinary] as $user) {
        $this->get($url.'?company='.$tenant->id.'&partner=Disabled&search='.$user->account_id)->assertOk()->assertInertia(fn ($p) => $p
            ->where('users.total', 1)->where('users.data.0.id', $user->id));
    }
    $this->get($url.'?company='.$tenant->id.'&partner=Disabled&search='.$active->account_id)->assertOk()->assertInertia(fn ($p) => $p->where('users.total', 0));
    $this->get($url.'?company='.$tenant->id.'&partner=Enabled&page=2')->assertOk()->assertInertia(fn ($p) => $p
        ->where('users.total', 1)->where('users.current_page', 2)->has('users.data', 0)
        ->where('users.prev_page_url', fn ($value) => str_contains($value, 'partner=Enabled') && str_contains($value, 'company='.$tenant->id)));
    $this->getJson($url.'?partner=Invalid')->assertUnprocessable();
    expect(DB::table('ledger_accounts')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(DB::table('partner_configurations')->orderBy('id')->get()->toJson())->toBe($partners);
    Http::assertNothingSent();
});
