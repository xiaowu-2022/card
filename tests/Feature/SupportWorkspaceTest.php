<?php

use App\Application\Support\SupportBot;
use App\Application\Support\SupportHours;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Support\Models\SupportConversation;
use App\Domain\Support\Models\SupportMessage;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Http::preventStrayRequests();
    Storage::fake('private');
    config(['inertia.ssr.enabled' => false]);
    $this->company = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $this->other = Tenant::where('slug', 'tenant-b')->firstOrFail();
    $this->customer = User::where('tenant_id', $this->company->id)->firstOrFail();
    $this->owner = AdminUser::where('email', 'owner@platform.local')->firstOrFail();
    $this->actingAs($this->owner, 'platform_admin');
    $this->hours = app(SupportHours::class);
    $this->accountInput = ['name' => 'Offline Agent', 'email' => 'support-fixture@example.test', 'password' => 'SyntheticPass123', 'support_name' => '小林', 'companies' => [$this->company->id, $this->other->id], 'enabled' => true, 'revision' => 0];
});

it('uses company-local inclusive opening and exclusive closing times, overnight weeks and default all day', function () {
    $this->company->update(['timezone' => 'Asia/Kuala_Lumpur']);
    expect($this->hours->availability($this->company->id)['available'])->toBeTrue()->and(DB::table('support_hours')->count())->toBe(0);
    $weekly = array_fill(0, 7, []);
    $weekly[0] = [['start' => '09:00', 'end' => '12:00'], ['start' => '13:00', 'end' => '18:00']];
    $weekly[6] = [['start' => '22:00', 'end' => '02:00']];
    $this->hours->save($this->owner->id, $this->company->id, ['weekly' => $weekly, 'revision' => 0]);
    foreach ([['2026-10-05 00:59', false], ['2026-10-05 01:00', true], ['2026-10-05 04:00', false], ['2026-10-05 05:00', true], ['2026-10-05 10:00', false], ['2026-10-04 17:59', true], ['2026-10-04 18:00', false]] as [$time,$open]) {
        expect($this->hours->availability($this->company->id, CarbonImmutable::parse($time, 'UTC'))['available'])->toBe($open);
    }
    expect($this->hours->availability($this->company->id, CarbonImmutable::parse('2026-10-05 12:00', 'Asia/Kuala_Lumpur'))['nextOpenAt'])->toBe('2026-10-05T13:00:00+08:00');
    expect($this->hours->availability($this->other->id)['available'])->toBeTrue();
    $this->postJson('http://admin.localhost/platform/support/hours', ['company' => $this->company->id, 'weekly' => $weekly, 'revision' => 0])->assertConflict();
    $weekly[0][] = ['start' => '01:00', 'end' => '03:00'];
    $this->postJson('http://admin.localhost/platform/support/hours', ['company' => $this->company->id, 'weekly' => $weekly, 'revision' => 1])->assertUnprocessable();
});

it('rejects offline handoff without writes, permits queued messages and replays a successful handoff after closing', function () {
    app(SupportBot::class)->configure($this->company->id, $this->owner->id, true, 0);
    $id = (string) Str::uuid();
    $this->actingAs($this->customer, 'tenant_user')->postJson('http://a.localhost/api/v1/support/handoff', ['request_id' => $id])->assertNoContent();
    $this->hours->save($this->owner->id, $this->company->id, ['weekly' => array_fill(0, 7, []), 'revision' => 0]);
    $count = SupportMessage::count();
    $this->postJson('http://a.localhost/api/v1/support/handoff', ['request_id' => $id])->assertNoContent();
    $this->postJson('http://a.localhost/api/v1/support/handoff', ['request_id' => (string) Str::uuid()])->assertConflict()->assertJsonPath('error.code', 'SUPPORT_OFFLINE');
    expect(SupportMessage::count())->toBe($count)->and(SupportConversation::sole()->mode)->toBe('WAITING');
    $this->postJson('http://a.localhost/api/v1/support/messages', ['request_id' => (string) Str::uuid(), 'support_message' => '下班后的留言'])->assertNoContent();
    expect(SupportMessage::count())->toBe($count + 1);
    $this->getJson('http://a.localhost/api/v1/support')->assertJsonPath('humanSupport.available', false)->assertJsonPath('humanSupport.nextOpenAt', null);
});

it('separates hours, shared reply and agent management permissions from sending', function () {
    foreach (['support.hours.manage', 'support.replies.manage', 'support.agents.manage'] as $name) {
        DB::table('role_permissions')->where('role_id', Role::where('name', 'PLATFORM_OWNER')->value('id'))->where('permission_id', DB::table('permissions')->where('name', $name)->value('id'))->delete();
    }
    $this->get('http://admin.localhost/platform/support/hours')->assertForbidden();
    $this->get('http://admin.localhost/platform/support/replies')->assertForbidden();
    $this->postJson('http://admin.localhost/platform/tenants/'.$this->company->id.'/users/'.$this->customer->id.'/support-agent', ['enabled' => true, 'revision' => 0])->assertForbidden();
    $this->get('http://admin.localhost/platform/support')->assertOk();
});

it('handles daylight-saving gaps and repeated local opening times', function () {
    $this->company->update(['timezone' => 'America/New_York']);
    $week = array_fill(0, 7, []);
    $week[6] = [['start' => '02:30', 'end' => '03:15']];
    $this->hours->save($this->owner->id, $this->company->id, ['weekly' => $week, 'revision' => 0]);
    expect($this->hours->availability($this->company->id, CarbonImmutable::parse('2026-03-08T06:00:00Z'))['nextOpenAt'])->toBe('2026-03-08T03:00:00-04:00');
    $week[6] = [['start' => '01:30', 'end' => '01:45']];
    $this->hours->save($this->owner->id, $this->company->id, ['weekly' => $week, 'revision' => 1]);
    expect($this->hours->availability($this->company->id, CarbonImmutable::parse('2026-11-01T05:50:00Z'))['nextOpenAt'])->toBe('2026-11-01T01:30:00-05:00');
});

it('rejects a first offline handoff and keeps new bot questions working', function () {
    app(SupportBot::class)->configure($this->company->id, $this->owner->id, true, 0);
    $this->hours->save($this->owner->id, $this->company->id, ['weekly' => array_fill(0, 7, []), 'revision' => 0]);
    $this->actingAs($this->customer, 'tenant_user')->postJson('http://a.localhost/api/v1/support/handoff', ['request_id' => (string) Str::uuid()])->assertConflict();
    expect(SupportConversation::count())->toBe(0)->and(DB::table('support_transitions')->count())->toBe(0);
    $this->postJson('http://a.localhost/api/v1/support/messages', ['request_id' => (string) Str::uuid(), 'support_message' => '测试未知问题'])->assertNoContent();
    expect(SupportMessage::where('is_bot', true)->sole()->support_message)->toContain('当前客服不在线');
});
