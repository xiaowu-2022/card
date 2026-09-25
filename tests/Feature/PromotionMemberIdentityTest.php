<?php

use App\Application\Promotion\PromotionMembershipAction;
use App\Application\Promotion\PromotionReportQuery;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserProfile;

it('returns current nicknames and only masked emails for owned direct members', function () {
    $this->seed();
    $tenant = Tenant::where('slug', 'tenant-a')->firstOrFail();
    $user = User::where('tenant_id', $tenant->id)->firstOrFail();
    $members = app(PromotionMembershipAction::class);
    $parent = $members->ensure($tenant->id, $user->id);
    $child = $user->replicate(['account_id']);
    $child->forceFill(['email' => 'member-private@example.test'])->save();
    $child->refresh();
    $members->ensure($tenant->id, $child->id, $parent->id);
    UserProfile::create(['tenant_id' => $tenant->id, 'user_id' => $child->id, 'display_name' => '成员昵称']);
    $query = app(PromotionReportQuery::class);
    $result = $query->members($tenant->id, $user->id, []);
    $row = collect($result['items'])->firstWhere('accountId', $child->account_id);
    expect($row['displayName'])->toBe('成员昵称')->and($row['maskedEmail'])->toBe('m***@example.test');
    expect(json_encode($result))->not->toContain('member-private@example.test');
    expect($query->members($tenant->id, $child->id, [])['items'])->toBe([]);
    UserProfile::where('user_id', $child->id)->update(['display_name' => '新昵称']);
    expect(collect($query->members($tenant->id, $user->id, [])['items'])->firstWhere('accountId', $child->account_id)['displayName'])->toBe('新昵称');
});
