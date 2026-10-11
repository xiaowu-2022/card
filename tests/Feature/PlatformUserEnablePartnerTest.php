<?php

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('requires confirmation and enables only the scoped user while preserving existing partner shares', function () {
    $this->seed();
    Http::preventStrayRequests();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->firstOrFail();
    $owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $before = DB::table('ledger_accounts')->orderBy('id')->get()->toJson();
    $url = 'http://admin.localhost/platform/tenants/'.$tenant->id.'/users/'.$user->id.'/partner';
    $this->actingAs($owner, 'platform_admin');
    $this->postJson($url, [])->assertUnprocessable();
    $this->postJson($url, ['confirmed' => false])->assertUnprocessable();
    expect(DB::table('partner_configurations')->where('user_id', $user->id)->exists())->toBeFalse();
    $this->postJson($url, ['confirmed' => true])->assertOk();
    $partner = DB::table('partner_configurations')->where('user_id', $user->id)->first();
    expect((bool) $partner->enabled)->toBeTrue()->and((float) $partner->share_percent)->toBe(40.0);
    expect($user->fresh()->card_transfer_blocked)->toBeTrue();
    $this->postJson($url, ['confirmed' => true])->assertOk()->assertJsonPath('partnerId', $partner->id);
    DB::table('partner_configurations')->where('id', $partner->id)->update(['enabled' => false, 'share_percent' => '17.5']);
    $this->postJson($url, ['confirmed' => true])->assertOk();
    expect((float) DB::table('partner_configurations')->where('id', $partner->id)->value('share_percent'))->toBe(17.5);
    $other = User::where('tenant_id', '<>', $tenant->id)->firstOrFail();
    $this->postJson(str_replace($user->id, $other->id, $url), ['confirmed' => true])->assertNotFound();
    expect(DB::table('ledger_accounts')->orderBy('id')->get()->toJson())->toBe($before);
    Http::assertNothingSent();
});
